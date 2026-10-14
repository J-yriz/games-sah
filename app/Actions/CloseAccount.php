<?php

namespace App\Actions;

use App\Models\ChipTransaction;
use App\Models\PokerAction;
use App\Models\PokerHandPlayer;
use App\Models\RoomMember;
use App\Models\TableStack;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Account closure policy (Tahap E).
 *
 * Live table value blocks closure: non-empty stacks or active room
 * membership must be resolved first so no chips are stranded mid-game.
 * Wallet funds never block: deactivation preserves the wallet row and its
 * balance untouched (hard delete only removes footprint-free accounts).
 *
 * Accounts with any historical footprint (ledger, memberships, stacks,
 * poker snapshots) are DEACTIVATED — credentials scrambled, history and
 * ledger rows preserved untouched. Nothing in chip_transactions is ever
 * edited or removed here.
 */
class CloseAccount
{
    /**
     * @return array{mode: 'deleted'|'deactivated', user_id: int}
     *
     * @throws AccountBlockedException
     */
    public function handle(User $user): array
    {
        if (TableStack::where('user_id', $user->id)->where('balance', '>', 0)->exists()) {
            throw new AccountBlockedException('Cash out your table stacks before closing your account.');
        }

        if (RoomMember::where('user_id', $user->id)->where('status', RoomMember::STATUS_ACTIVE)->exists()) {
            throw new AccountBlockedException('Leave all rooms before closing your account.');
        }

        $walletId = $user->wallet?->id;

        $hasLedgerFootprint = $walletId !== null && ChipTransaction::where(function ($q) use ($walletId): void {
            $q->where('from_wallet_id', $walletId)->orWhere('to_wallet_id', $walletId);
        })->exists();

        $hasFootprint = ($walletId !== null)
            || $hasLedgerFootprint
            || RoomMember::where('user_id', $user->id)->exists()
            || TableStack::where('user_id', $user->id)->exists()
            || PokerHandPlayer::where('user_id', $user->id)->exists()
            || PokerAction::where('user_id', $user->id)->exists();

        if (! $hasFootprint) {
            $user->delete();

            return ['mode' => 'deleted', 'user_id' => $user->id];
        }

        return DB::transaction(function () use ($user): array {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'name' => 'Deleted user',
                'username' => 'deleted_'.$locked->id,
                'email' => 'deleted_'.$locked->id.'_'.Str::random(8).'@deleted.local',
                'email_verified_at' => null,
                'password' => Str::random(64),
                'remember_token' => null,
            ])->save();

            return ['mode' => 'deactivated', 'user_id' => $locked->id];
        });
    }
}
