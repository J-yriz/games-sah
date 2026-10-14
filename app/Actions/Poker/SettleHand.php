<?php

namespace App\Actions\Poker;

use App\Actions\Poker\Exceptions\HandNotFoundException;
use App\Actions\Poker\Exceptions\InvalidHandStateException;
use App\Actions\Poker\Exceptions\InvalidSettlementException;
use App\Actions\Poker\Exceptions\PlayerNotInHandException;
use App\Events\PokerHandStateUpdated;
use App\Models\ChipTransaction;
use App\Models\PokerHand;
use App\Models\PokerHandPlayer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Handle showdown, pot calculation, and settlement.
 */
class SettleHand
{
    /**
     * @throws HandNotFoundException
     * @throws InvalidHandStateException
     * @throws PlayerNotInHandException
     * @throws InvalidSettlementException
     */
    public function handle(
        User $host,
        PokerHand $hand,
        array $winners, // [pot_index => [user_id, ...]]
        string $idempotencyKey,
    ): array {
        $this->validateIdempotencyKey($idempotencyKey);

        $existing = PokerHand::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing);
        }

        if (! in_array($hand->status, [
            PokerHand::STATUS_AWAITING_SHOWDOWN,
            PokerHand::STATUS_SETTLED,
        ], true)) {
            throw new InvalidHandStateException('Hand is not ready for settlement.');
        }

        // Only host can settle
        $room = $hand->room;
        if ($room->host_user_id !== $host->id) {
            throw new InvalidSettlementException('Only the room host can settle the hand.');
        }

        try {
            $result = DB::transaction(function () use ($hand, $winners, $idempotencyKey): array {
                $lockedHand = PokerHand::whereKey($hand->id)->lockForUpdate()->firstOrFail();

                if (! in_array($lockedHand->status, [
                    PokerHand::STATUS_AWAITING_SHOWDOWN,
                    PokerHand::STATUS_SETTLED,
                ], true)) {
                    throw new InvalidHandStateException('Hand is not ready for settlement.');
                }

                // Load all hand players with stacks
                $handPlayers = $lockedHand->players()
                    ->whereIn('status', [PokerHandPlayer::STATUS_ACTIVE, PokerHandPlayer::STATUS_ALL_IN])
                    ->with('stack')
                    ->get()
                    ->keyBy('user_id');

                if ($handPlayers->isEmpty()) {
                    throw new InvalidSettlementException('No eligible players for settlement.');
                }

                // Calculate pot layers (main pot + side pots)
                $potLayers = $this->calculatePotLayers($handPlayers);

                // Validate winners against eligible players for each pot
                $this->validateWinners($potLayers, $winners, $handPlayers);

                // Distribute pots
                $distributions = [];
                foreach ($potLayers as $index => $layer) {
                    $layerWinners = $winners[$index] ?? [];
                    if (empty($layerWinners)) {
                        continue;
                    }

                    $distribution = $this->distributePot(
                        $lockedHand,
                        $layer,
                        $layerWinners,
                        $handPlayers,
                        $idempotencyKey.':pot:'.$index,
                    );
                    $distributions[] = $distribution;
                }

                // Handle uncalled bet returns
                $uncalledReturns = $this->returnUncalledExcess($lockedHand, $handPlayers, $idempotencyKey.':uncalled');

                // Update hand status
                $lockedHand->status = PokerHand::STATUS_SETTLED;
                $lockedHand->completed_at = now();
                $lockedHand->pot_layers = $potLayers;
                $lockedHand->idempotency_key = $idempotencyKey;
                $lockedHand->save();

                PokerHandStateUpdated::dispatch($lockedHand->id);

                return [
                    'hand' => $lockedHand,
                    'pot_layers' => $potLayers,
                    'distributions' => $distributions,
                    'uncalled_returns' => $uncalledReturns,
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $existing = PokerHand::where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    return $this->replay($existing);
                }
            }
            throw $e;
        }
    }

    /**
     * Calculate pot layers from player contributions.
     * Returns array of layers: [amount, eligible_user_ids]
     */
    private function calculatePotLayers(array $handPlayers): array
    {
        // Sort players by total_contribution ascending
        $sorted = $handPlayers->sortBy('total_contribution')->values();
        $layers = [];

        $previousContribution = 0;
        $eligibleCount = $sorted->count();

        foreach ($sorted as $index => $player) {
            $contribution = $player->total_contribution;

            if ($contribution > $previousContribution) {
                $delta = $contribution - $previousContribution;
                $potAmount = $delta * $eligibleCount;

                if ($potAmount > 0) {
                    $eligibleIds = $sorted->slice($index)->pluck('user_id')->toArray();
                    $layers[] = [
                        'amount' => $potAmount,
                        'eligible' => $eligibleIds,
                    ];
                }

                $previousContribution = $contribution;
            }

            $eligibleCount--;
        }

        return $layers;
    }

    /**
     * Validate that winners are eligible for their pots.
     */
    private function validateWinners(array $potLayers, array $winners, array $handPlayers): void
    {
        foreach ($potLayers as $index => $layer) {
            $layerWinners = $winners[$index] ?? [];

            if (empty($layerWinners)) {
                throw new InvalidSettlementException(
                    "No winners specified for pot layer {$index}."
                );
            }

            foreach ($layerWinners as $winnerId) {
                if (! in_array($winnerId, $layer['eligible'], true)) {
                    throw new InvalidSettlementException(
                        "Winner {$winnerId} is not eligible for pot layer {$index}."
                    );
                }

                $player = $handPlayers->get($winnerId);
                if ($player === null || $player->status === PokerHandPlayer::STATUS_FOLDED) {
                    throw new InvalidSettlementException(
                        "Winner {$winnerId} is folded or not in hand."
                    );
                }
            }
        }
    }

    /**
     * Distribute a single pot layer among winners.
     */
    private function distributePot(
        PokerHand $hand,
        array $layer,
        array $winnerIds,
        array $handPlayers,
        string $idempotencyKey,
    ): array {
        $potAmount = $layer['amount'];
        $winnerCount = count($winnerIds);
        $baseShare = intdiv($potAmount, $winnerCount);
        $remainder = $potAmount % $winnerCount;

        // Determine odd chip recipient: first winner in seat order from dealer
        $oddChipWinner = $this->determineOddChipWinner($winnerIds, $hand);

        $distribution = [];

        foreach ($winnerIds as $index => $winnerId) {
            $player = $handPlayers->get($winnerId);
            if ($player === null || $player->stack === null) {
                continue;
            }

            $share = $baseShare + ($winnerId === $oddChipWinner && $remainder > 0 ? 1 : 0);

            if ($share > 0) {
                $stack = $player->stack;
                $stack->balance += $share;
                $stack->save();

                // Ledger: hand (escrow) -> stack
                ChipTransaction::create([
                    'from_poker_hand_id' => $hand->id,
                    'to_table_stack_id' => $stack->id,
                    'to_hand_player_id' => $player->id,
                    'type' => ChipTransaction::TYPE_POT_WIN,
                    'amount' => $share,
                    'idempotency_key' => $idempotencyKey.':winner:'.$winnerId,
                    'metadata' => [
                        'hand_id' => $hand->id,
                        'player_id' => $player->id,
                        'pot_layer' => $index,
                        'username' => $player->user->username,
                    ],
                ]);

                $distribution[] = [
                    'user_id' => $winnerId,
                    'amount' => $share,
                    'stack_after' => $stack->balance,
                ];
            }
        }

        return [
            'pot_amount' => $potAmount,
            'winners' => $distribution,
        ];
    }

    /**
     * Return uncalled bet excess to the player who made the bet that wasn't fully called.
     */
    private function returnUncalledExcess(PokerHand $hand, array $handPlayers, string $idempotencyKey): array
    {
        $returns = [];

        // Find players who bet/raised more than anyone else called
        // This is handled by pot layer calculation - the last layer only includes the aggressor
        // The uncalled portion is already not included in any pot layer
        // But we need to return the excess to the aggressor

        $maxContribution = $handPlayers->max('total_contribution');
        $aggressors = $handPlayers->filter(fn ($p) => $p->total_contribution === $maxContribution);

        // If there's only one aggressor and their contribution exceeds the next highest,
        // return the difference
        if ($aggressors->count() === 1) {
            $aggressor = $aggressors->first();
            $secondMax = $handPlayers->filter(fn ($p) => $p->user_id !== $aggressor->user_id)
                ->max('total_contribution') ?? 0;

            $excess = $maxContribution - $secondMax;
            if ($excess > 0 && $aggressor->stack !== null) {
                $stack = $aggressor->stack;
                $stack->balance += $excess;
                $stack->save();

                ChipTransaction::create([
                    'from_poker_hand_id' => $hand->id,
                    'to_table_stack_id' => $stack->id,
                    'to_hand_player_id' => $aggressor->id,
                    'type' => ChipTransaction::TYPE_UNCALLED_RETURN,
                    'amount' => $excess,
                    'idempotency_key' => $idempotencyKey.':excess:'.$aggressor->user_id,
                    'metadata' => [
                        'hand_id' => $hand->id,
                        'player_id' => $aggressor->id,
                        'username' => $aggressor->user->username,
                    ],
                ]);

                $returns[] = [
                    'user_id' => $aggressor->user_id,
                    'amount' => $excess,
                    'stack_after' => $stack->balance,
                ];
            }
        }

        return $returns;
    }

    /**
     * Determine odd chip winner using seat order from dealer.
     */
    private function determineOddChipWinner(array $winnerIds, PokerHand $hand): ?int
    {
        $winnerPlayers = $hand->players()
            ->whereIn('user_id', $winnerIds)
            ->whereIn('status', [PokerHandPlayer::STATUS_ACTIVE, PokerHandPlayer::STATUS_ALL_IN])
            ->orderBy('seat')
            ->get();

        if ($winnerPlayers->isEmpty()) {
            return null;
        }

        $dealerSeat = $hand->dealer_seat;

        // Find first winner seat after dealer
        foreach ($winnerPlayers as $player) {
            if ($player->seat > $dealerSeat) {
                return $player->user_id;
            }
        }

        // Wrap around
        return $winnerPlayers->first()->user_id;
    }

    private function validateIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 64) {
            throw new \InvalidArgumentException('A valid operation ID is required.');
        }
    }

    private function replay(PokerHand $existing): array
    {
        return [
            'hand' => $existing,
            'pot_layers' => $existing->pot_layers ?? [],
            'distributions' => [],
            'uncalled_returns' => [],
            'replayed' => true,
        ];
    }

    private function validateIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 64) {
            throw new \InvalidArgumentException('A valid operation ID is required.');
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'idempotency_key')
            || in_array($e->getCode(), ['23000', '23505'], true);
    }
}
