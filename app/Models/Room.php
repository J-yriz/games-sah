<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $host_user_id
 * @property string $name
 * @property string $invite_code
 * @property string $game
 * @property int $max_players
 * @property int $min_buy_in
 * @property int|null $max_buy_in
 * @property int $small_blind
 * @property int $big_blind
 * @property int $min_raise_multiplier
 * @property int $action_timer
 * @property array<string, mixed>|null $settings
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Room extends Model
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const ROLE_HOST = 'host';

    public const ROLE_PLAYER = 'player';

    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected $fillable = [
        'host_user_id',
        'name',
        'invite_code',
        'game',
        'max_players',
        'min_buy_in',
        'max_buy_in',
        'small_blind',
        'big_blind',
        'min_raise_multiplier',
        'action_timer',
        'settings',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_players' => 'integer',
            'min_buy_in' => 'integer',
            'max_buy_in' => 'integer',
            'small_blind' => 'integer',
            'big_blind' => 'integer',
            'min_raise_multiplier' => 'integer',
            'action_timer' => 'integer',
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /**
     * @return HasMany<RoomMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(RoomMember::class);
    }

    /**
     * @return HasMany<TableStack, $this>
     */
    public function stacks(): HasMany
    {
        return $this->hasMany(TableStack::class);
    }

    /**
     * @return HasMany<PokerHand, $this>
     */
    public function pokerHands(): HasMany
    {
        return $this->hasMany(PokerHand::class);
    }

    public function isOpenForJoin(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }
}
