<?php

namespace App\Models;

use Database\Factories\PokerHandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $room_id
 * @property int $hand_number
 * @property int|null $dealer_user_id
 * @property int|null $dealer_seat
 * @property int $small_blind_amount
 * @property int $big_blind_amount
 * @property string $street
 * @property string $status
 * @property int|null $current_turn_user_id
 * @property int $current_bet
 * @property int $min_full_raise
 * @property int|null $last_aggressor_user_id
 * @property int $total_pot
 * @property array<int, mixed>|null $pot_layers
 * @property int $elapsed_seconds
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $settlement_key
 * @property Carbon|null $turn_started_at
 * @property Carbon|null $turn_deadline_at
 * @property bool $turn_extended
 * @property string|null $cancel_reason
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancel_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PokerHand extends Model
{
    public const STREET_PREFLOP = 'preflop';

    public const STREET_FLOP = 'flop';

    public const STREET_TURN = 'turn';

    public const STREET_RIVER = 'river';

    public const STREET_SHOWDOWN = 'showdown';

    public const STATUS_PREFLOP = 'preflop';

    public const STATUS_AWAITING_FLOP = 'awaiting_flop';

    public const STATUS_FLOP = 'flop';

    public const STATUS_AWAITING_TURN = 'awaiting_turn';

    public const STATUS_TURN = 'turn';

    public const STATUS_AWAITING_RIVER = 'awaiting_river';

    public const STATUS_RIVER = 'river';

    public const STATUS_AWAITING_SHOWDOWN = 'awaiting_showdown';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    /** @use HasFactory<PokerHandFactory> */
    use HasFactory;

    protected $fillable = [
        'room_id',
        'hand_number',
        'dealer_user_id',
        'dealer_seat',
        'small_blind_amount',
        'big_blind_amount',
        'street',
        'status',
        'current_turn_user_id',
        'current_bet',
        'min_full_raise',
        'last_aggressor_user_id',
        'total_pot',
        'pot_layers',
        'elapsed_seconds',
        'started_at',
        'completed_at',
        'idempotency_key',
        'settlement_key',
        'turn_started_at',
        'turn_deadline_at',
        'turn_extended',
        'cancel_reason',
        'cancelled_by_user_id',
        'cancel_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'small_blind_amount' => 'integer',
            'big_blind_amount' => 'integer',
            'current_bet' => 'integer',
            'min_full_raise' => 'integer',
            'total_pot' => 'integer',
            'pot_layers' => 'array',
            'elapsed_seconds' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'turn_started_at' => 'datetime',
            'turn_deadline_at' => 'datetime',
            'turn_extended' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dealer_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function currentTurn(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_turn_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lastAggressor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_aggressor_user_id');
    }

    /**
     * @return HasMany<PokerHandPlayer, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(PokerHandPlayer::class, 'hand_id');
    }

    /**
     * @return HasMany<PokerAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(PokerAction::class);
    }

    /**
     * @return HasMany<ChipTransaction, $this>
     */
    public function fromTransactions(): HasMany
    {
        return $this->hasMany(ChipTransaction::class, 'from_poker_hand_id');
    }

    /**
     * @return HasMany<ChipTransaction, $this>
     */
    public function toTransactions(): HasMany
    {
        return $this->hasMany(ChipTransaction::class, 'to_poker_hand_id');
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, [self::STATUS_SETTLED, self::STATUS_CANCELLED], true);
    }

    public function isBettingStreet(): bool
    {
        return in_array($this->street, [self::STREET_PREFLOP, self::STREET_FLOP, self::STREET_TURN, self::STREET_RIVER], true);
    }

    public function isAwaitingHost(): bool
    {
        return in_array($this->status, [
            self::STATUS_AWAITING_FLOP,
            self::STATUS_AWAITING_TURN,
            self::STATUS_AWAITING_RIVER,
            self::STATUS_AWAITING_SHOWDOWN,
        ], true);
    }
}
