<?php

namespace App\Actions\Poker;

use App\Events\PokerHandStateUpdated;
use App\Events\RoomStateUpdated;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\User;
use App\Support\TurnTimer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enforce expired action timers.
 *
 * Safe under concurrent workers: every hand is locked first and the
 * deadline, turn holder, and status are re-validated inside the lock, so
 * only one worker can consume a given turn. A manual action that lands
 * first advances the turn (new deadline), making a racing timeout a no-op.
 *
 * Policy: one extension per turn when the room configures it, otherwise
 * auto-check when free, else auto-fold. Timeout actions flow through the
 * normal PlayerAction validation and ledger path, flagged as timeouts.
 */
class ProcessTimeouts
{
    /**
     * @return array{processed: int, acted: int, extended: int, skipped: int}
     */
    public function handle(?int $handId = null): array
    {
        $query = PokerHand::whereIn('status', [
            PokerHand::STATUS_PREFLOP,
            PokerHand::STATUS_FLOP,
            PokerHand::STATUS_TURN,
            PokerHand::STATUS_RIVER,
        ])
            ->whereNotNull('current_turn_user_id')
            ->whereNotNull('turn_deadline_at')
            ->where('turn_deadline_at', '<=', Carbon::now());

        if ($handId !== null) {
            $query->whereKey($handId);
        }

        $ids = $query->pluck('id')->all();
        $summary = ['processed' => 0, 'acted' => 0, 'extended' => 0, 'skipped' => 0];

        foreach ($ids as $id) {
            try {
                $outcome = $this->processOne($id);
            } catch (Throwable $e) {
                // One hand must never block unrelated hands. Deadlocks and
                // transient errors are retried on the next scheduler pass.
                Log::warning('Poker timeout processing failed for a hand.', [
                    'hand_id' => $id,
                    'exception' => get_class($e),
                    'code' => $e->getCode(),
                ]);
                $summary['processed']++;
                $summary['skipped']++;

                continue;
            }
            $summary['processed']++;
            $summary[$outcome]++;
        }

        if ($summary['processed'] > 0) {
            Log::info('Poker timeout sweep finished.', $summary);
        }

        return $summary;
    }

    /**
     * @return 'acted'|'extended'|'skipped'
     */
    private function processOne(int $handId): string
    {
        $acted = false;
        $extended = false;

        DB::transaction(function () use ($handId, &$acted, &$extended): void {
            $hand = PokerHand::whereKey($handId)->lockForUpdate()->first();

            if ($hand === null
                || ! in_array($hand->status, [
                    PokerHand::STATUS_PREFLOP,
                    PokerHand::STATUS_FLOP,
                    PokerHand::STATUS_TURN,
                    PokerHand::STATUS_RIVER,
                ], true)
                || $hand->current_turn_user_id === null
                || $hand->turn_deadline_at === null
                || $hand->turn_deadline_at->isFuture()) {
                return;
            }

            $turnUserId = $hand->current_turn_user_id;
            $turnStarted = (string) ($hand->turn_started_at ?? 'none');

            $player = PokerHandPlayer::where('hand_id', $hand->id)
                ->where('user_id', $turnUserId)
                ->lockForUpdate()
                ->first();

            // Turn holder can no longer act (folded/all-in/out, or the turn
            // moved on while this worker waited): nothing to do. If the turn
            // is genuinely stuck, the next scheduler pass re-arms via action
            // flow; never invent a transition here.
            if ($player === null || $player->status !== PokerHandPlayer::STATUS_ACTIVE) {
                TurnTimer::clear($hand);

                return;
            }

            $extension = (int) ($hand->room->action_timer_extension ?? 0);

            if ($extension > 0 && ! $hand->turn_extended) {
                $now = Carbon::now();
                $hand->turn_started_at = $now;
                $hand->turn_deadline_at = $now->copy()->addSeconds($extension);
                $hand->turn_extended = true;
                $hand->save();
                $extended = true;

                PokerHandStateUpdated::dispatch($hand->id, 'timeout_extended');
                RoomStateUpdated::dispatch($hand->room_id);

                return;
            }

            $user = User::find($turnUserId);

            if ($user === null) {
                TurnTimer::clear($hand);

                return;
            }

            $canCheck = (int) $player->street_contribution >= (int) $hand->current_bet;
            $action = $canCheck ? 'check' : 'fold';

            // Unique per turn: same-turn replays collapse, a new turn gets a
            // new key because turn_started_at changes on every re-arm.
            $key = 'timeout:'.$hand->id.':'.$turnUserId.':'.$hand->street.':'.md5($turnStarted);

            app(PlayerAction::class)->handle($user, $hand, $action, $key, null, null, true);
            $acted = true;
        });

        if ($acted) {
            return 'acted';
        }

        return $extended ? 'extended' : 'skipped';
    }

    public static function timeoutKey(int $handId, int $userId, string $street, string $turnStarted): string
    {
        return 'timeout:'.$handId.':'.$userId.':'.$street.':'.md5($turnStarted);
    }
}
