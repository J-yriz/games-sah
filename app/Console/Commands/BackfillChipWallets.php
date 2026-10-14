<?php

namespace App\Console\Commands;

use App\Models\ChipTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Create wallets plus the one-time initial grant for accounts that
 * predate the chip ledger. Safe to rerun: existing wallets are skipped
 * and the grant key is deterministic per user.
 */
class BackfillChipWallets extends Command
{
    protected $signature = 'chips:backfill-wallets';

    protected $description = 'Backfill wallets and initial chip grants for legacy accounts.';

    public function handle(): int
    {
        $grant = (int) config('chips.initial_grant');

        $created = 0;

        User::whereDoesntHave('wallet')->chunkById(200, function ($users) use ($grant, &$created): void {
            foreach ($users as $user) {
                DB::transaction(function () use ($user, $grant, &$created): void {
                    $wallet = Wallet::create([
                        'user_id' => $user->id,
                        'balance' => $grant,
                    ]);

                    ChipTransaction::firstOrCreate(
                        ['idempotency_key' => 'initial-grant:'.$user->id],
                        [
                            'from_wallet_id' => null,
                            'to_wallet_id' => $wallet->id,
                            'type' => ChipTransaction::TYPE_INITIAL_GRANT,
                            'amount' => $grant,
                            'metadata' => ['user_id' => $user->id, 'grant' => 'initial-backfill'],
                        ],
                    );

                    $created++;
                });
            }
        });

        // Repair wallets whose balance row exists but whose grant ledger row
        // was never written (e.g. interrupted registration).
        Wallet::whereDoesntHave('receivedTransactions', function ($query): void {
            $query->where('type', ChipTransaction::TYPE_INITIAL_GRANT);
        })->with('user')->chunkById(200, function ($wallets) use ($grant): void {
            foreach ($wallets as $wallet) {
                if ($wallet->sentTransactions()->exists()) {
                    continue;
                }

                DB::transaction(function () use ($wallet, $grant): void {
                    ChipTransaction::firstOrCreate(
                        ['idempotency_key' => 'initial-grant:'.$wallet->user_id],
                        [
                            'from_wallet_id' => null,
                            'to_wallet_id' => $wallet->id,
                            'type' => ChipTransaction::TYPE_INITIAL_GRANT,
                            'amount' => $grant,
                            'metadata' => ['user_id' => $wallet->user_id, 'grant' => 'initial-repair'],
                        ],
                    );
                });
            }
        });

        $this->info("Backfilled {$created} wallet(s).");

        return self::SUCCESS;
    }
}
