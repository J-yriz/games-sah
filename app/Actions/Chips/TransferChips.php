<?php

namespace App\Actions\Chips;

use App\Actions\Chips\Exceptions\IdempotencyConflictException;
use App\Actions\Chips\Exceptions\InsufficientBalanceException;
use App\Actions\Chips\Exceptions\InvalidTransferAmountException;
use App\Actions\Chips\Exceptions\RecipientIneligibleException;
use App\Actions\Chips\Exceptions\RecipientNotFoundException;
use App\Actions\Chips\Exceptions\SelfTransferException;
use App\Events\ChipBalanceUpdated;
use App\Models\ChipTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Move chips between two account wallets atomically.
 *
 * The sender always comes from the authenticated user supplied by the
 * caller. Never accept a sender identity from frontend input.
 */
class TransferChips
{
    /**
     * @throws InvalidTransferAmountException
     * @throws RecipientNotFoundException
     * @throws SelfTransferException
     * @throws RecipientIneligibleException
     * @throws InsufficientBalanceException
     * @throws IdempotencyConflictException
     */
    public function handle(
        User $sender,
        string $recipientUsername,
        int $amount,
        string $idempotencyKey,
        ?string $note = null,
    ): TransferResult {
        $recipientUsername = Str::lower(trim($recipientUsername));

        $this->validateAmount($amount);
        $this->validateIdempotencyKey($idempotencyKey);

        $senderWallet = $this->ensureWallet($sender);

        // Fast-path replay: same key, same parameters returns the
        // original transaction without touching balances.
        $existing = ChipTransaction::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing, $senderWallet->id, $recipientUsername, $amount);
        }

        $recipient = User::where('username', $recipientUsername)->first();

        if ($recipient === null) {
            throw new RecipientNotFoundException('Recipient not found.');
        }

        if ($recipient->id === $sender->id) {
            throw new SelfTransferException('Cannot transfer chips to yourself.');
        }

        if (! $recipient->hasVerifiedEmail()) {
            throw new RecipientIneligibleException('Recipient account is not eligible to receive chips.');
        }

        $recipientWallet = $this->ensureWallet($recipient);

        try {
            $transaction = DB::transaction(function () use (
                $senderWallet,
                $recipientWallet,
                $sender,
                $recipient,
                $amount,
                $idempotencyKey,
                $note,
            ): ChipTransaction {
                // Lock both rows in a consistent (ascending id) order so
                // concurrent transfers cannot deadlock.
                $locked = Wallet::whereIn('id', [$senderWallet->id, $recipientWallet->id])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $lockedSender = $locked->get($senderWallet->id);
                $lockedRecipient = $locked->get($recipientWallet->id);

                if ($lockedSender === null || $lockedRecipient === null) {
                    throw new RecipientNotFoundException('Wallet no longer exists.');
                }

                // Re-validate against the freshly locked balance: the
                // pre-transaction read may already be stale.
                if ($lockedSender->balance < $amount) {
                    throw new InsufficientBalanceException('Insufficient chip balance.');
                }

                $lockedSender->balance -= $amount;
                $lockedSender->save();

                $lockedRecipient->balance += $amount;
                $lockedRecipient->save();

                return ChipTransaction::create([
                    'from_wallet_id' => $lockedSender->id,
                    'to_wallet_id' => $lockedRecipient->id,
                    'type' => ChipTransaction::TYPE_TRANSFER,
                    'amount' => $amount,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => array_filter([
                        'sender_username' => $sender->username,
                        'recipient_username' => $recipient->username,
                        'note' => $note,
                    ]),
                ]);
            });
        } catch (QueryException $e) {
            // Lost race with an identical request: the unique constraint
            // fired, so resolve against the committed row.
            if ($this->isUniqueViolation($e)) {
                $existing = ChipTransaction::where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $this->replay($existing, $senderWallet->id, $recipientUsername, $amount);
                }
            }

            throw $e;
        }

        $senderWallet = Wallet::find($transaction->from_wallet_id);
        $recipientWallet = Wallet::find($transaction->to_wallet_id);
        $senderBalance = $senderWallet === null ? 0 : $senderWallet->balance;
        $recipientBalance = $recipientWallet === null ? 0 : $recipientWallet->balance;

        // Dispatched after commit (event implements
        // ShouldDispatchAfterCommit): never notifies on rollback.
        ChipBalanceUpdated::dispatch(
            $transaction->from_wallet_id, $senderBalance, $transaction, 'out', $recipient->username ?? ''
        );
        ChipBalanceUpdated::dispatch(
            $transaction->to_wallet_id, $recipientBalance, $transaction, 'in', $sender->username ?? ''
        );

        return new TransferResult($transaction, false, $senderBalance, $recipientBalance);
    }

    /**
     * @throws InvalidTransferAmountException
     */
    private function validateAmount(int $amount): void
    {
        $min = (int) config('chips.min_transfer', 1);
        $max = (int) config('chips.max_transfer', 1_000_000_000);

        if ($amount < $min || $amount > $max) {
            throw new InvalidTransferAmountException("Amount must be between {$min} and {$max} chips.");
        }
    }

    private function validateIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 64) {
            throw new \InvalidArgumentException('A valid operation ID is required.');
        }
    }

    /**
     * Legacy accounts may predate the ledger; grant them their wallet
     * (plus the one-time initial grant) on first use.
     */
    private function ensureWallet(User $user): Wallet
    {
        $wallet = $user->wallet;

        if ($wallet !== null) {
            return $wallet;
        }

        $grant = (int) config('chips.initial_grant');

        return DB::transaction(function () use ($user, $grant): Wallet {
            $wallet = Wallet::create(['user_id' => $user->id, 'balance' => $grant]);

            ChipTransaction::firstOrCreate(
                ['idempotency_key' => 'initial-grant:'.$user->id],
                [
                    'from_wallet_id' => null,
                    'to_wallet_id' => $wallet->id,
                    'type' => ChipTransaction::TYPE_INITIAL_GRANT,
                    'amount' => $grant,
                    'metadata' => ['user_id' => $user->id, 'grant' => 'initial-lazy'],
                ],
            );

            return $wallet;
        });
    }

    /**
     * @throws IdempotencyConflictException
     */
    private function replay(
        ChipTransaction $existing,
        int $senderWalletId,
        string $recipientUsername,
        int $amount,
    ): TransferResult {
        $matches = $existing->type === ChipTransaction::TYPE_TRANSFER
            && $existing->from_wallet_id === $senderWalletId
            && (int) $existing->amount === $amount
            && Str::lower((string) ($existing->metadata['recipient_username'] ?? '')) === $recipientUsername;

        if (! $matches) {
            throw new IdempotencyConflictException('Operation ID was already used with different parameters.');
        }

        // Balances are read fresh: later transfers may have moved them
        // since the original request committed.
        $senderWallet = Wallet::find($existing->from_wallet_id);
        $recipientWallet = Wallet::find($existing->to_wallet_id);
        $senderBalance = $senderWallet === null ? 0 : $senderWallet->balance;
        $recipientBalance = $recipientWallet === null ? 0 : $recipientWallet->balance;

        return new TransferResult($existing, true, $senderBalance, $recipientBalance);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'idempotency_key')
            || in_array($e->getCode(), ['23000', '23505'], true);
    }
}
