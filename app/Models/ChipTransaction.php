<?php

namespace App\Models;

use Database\Factories\ChipTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable ledger row. Rows are only ever created, never updated or
 * deleted through application code.
 *
 * @property int $id
 * @property int|null $from_wallet_id
 * @property int|null $to_wallet_id
 * @property int|null $from_table_stack_id
 * @property int|null $to_table_stack_id
 * @property int|null $from_poker_hand_id
 * @property int|null $to_poker_hand_id
 * @property int|null $from_hand_player_id
 * @property int|null $to_hand_player_id
 * @property string $type
 * @property int $amount
 * @property string $idempotency_key
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChipTransaction extends Model
{
    public const TYPE_INITIAL_GRANT = 'initial_grant';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_BUY_IN = 'buy_in';

    public const TYPE_REBUY = 'rebuy';

    public const TYPE_CASH_OUT = 'cash_out';

    // Poker transaction types
    public const TYPE_BLIND_POST = 'blind_post';

    public const TYPE_BET = 'bet';

    public const TYPE_CALL = 'call';

    public const TYPE_RAISE = 'raise';

    public const TYPE_POT_WIN = 'pot_win';

    public const TYPE_UNCALLED_RETURN = 'uncalled_return';

    /** @use HasFactory<ChipTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'from_wallet_id',
        'to_wallet_id',
        'from_table_stack_id',
        'to_table_stack_id',
        'from_poker_hand_id',
        'to_poker_hand_id',
        'from_hand_player_id',
        'to_hand_player_id',
        'type',
        'amount',
        'idempotency_key',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function fromWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id');
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function toWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id');
    }

    /**
     * @return BelongsTo<TableStack, $this>
     */
    public function fromStack(): BelongsTo
    {
        return $this->belongsTo(TableStack::class, 'from_table_stack_id');
    }

    /**
     * @return BelongsTo<TableStack, $this>
     */
    public function toStack(): BelongsTo
    {
        return $this->belongsTo(TableStack::class, 'to_table_stack_id');
    }

    /**
     * @return BelongsTo<PokerHand, $this>
     */
    public function fromPokerHand(): BelongsTo
    {
        return $this->belongsTo(PokerHand::class, 'from_poker_hand_id');
    }

    /**
     * @return BelongsTo<PokerHand, $this>
     */
    public function toPokerHand(): BelongsTo
    {
        return $this->belongsTo(PokerHand::class, 'to_poker_hand_id');
    }

    /**
     * @return BelongsTo<PokerHandPlayer, $this>
     */
    public function fromHandPlayer(): BelongsTo
    {
        return $this->belongsTo(PokerHandPlayer::class, 'from_hand_player_id');
    }

    /**
     * @return BelongsTo<PokerHandPlayer, $this>
     */
    public function toHandPlayer(): BelongsTo
    {
        return $this->belongsTo(PokerHandPlayer::class, 'to_hand_player_id');
    }
}
