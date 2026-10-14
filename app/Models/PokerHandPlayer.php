<?php

namespace App\Models;

use Database\Factories\PokerHandPlayerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $hand_id
 * @property int $user_id
 * @property int $room_id
 * @property int $seat
 * @property int $starting_stack
 * @property int $total_contribution
 * @property int $street_contribution
 * @property string $status
 * @property bool $acted_this_street
 * @property bool $is_dealer
 * @property bool $is_small_blind
 * @property bool $is_big_blind
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PokerHandPlayer extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_FOLDED = 'folded';

    public const STATUS_ALL_IN = 'all_in';

    public const STATUS_OUT = 'out';

    /** @use HasFactory<PokerHandPlayerFactory> */
    use HasFactory;

    protected $fillable = [
        'hand_id',
        'user_id',
        'room_id',
        'seat',
        'starting_stack',
        'total_contribution',
        'street_contribution',
        'status',
        'acted_this_street',
        'is_dealer',
        'is_small_blind',
        'is_big_blind',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starting_stack' => 'integer',
            'total_contribution' => 'integer',
            'street_contribution' => 'integer',
            'acted_this_street' => 'boolean',
            'is_dealer' => 'boolean',
            'is_small_blind' => 'boolean',
            'is_big_blind' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PokerHand, $this>
     */
    public function hand(): BelongsTo
    {
        return $this->belongsTo(PokerHand::class, 'hand_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<TableStack, $this>
     */
    public function stack(): BelongsTo
    {
        return $this->belongsTo(TableStack::class, 'user_id', 'user_id')
            ->where('room_id', $this->room_id);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function canAct(): bool
    {
        return $this->isActive() && ! $this->acted_this_street;
    }

    public function getCurrentBetAttribute(): int
    {
        return $this->hand->current_bet ?? 0;
    }
}
