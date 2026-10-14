<?php

namespace App\Models;

use Database\Factories\RoomMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $room_id
 * @property int $user_id
 * @property string $role
 * @property string $status
 * @property int|null $seat
 * @property Carbon|null $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RoomMember extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_LEFT = 'left';

    public const STATUS_REMOVED = 'removed';

    /** @use HasFactory<RoomMemberFactory> */
    use HasFactory;

    protected $fillable = [
        'room_id',
        'user_id',
        'role',
        'status',
        'joined_at',
        'seat',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'seat' => 'integer',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isHost(): bool
    {
        return $this->role === Room::ROLE_HOST;
    }
}
