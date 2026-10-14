<?php

namespace App\Models;

use Database\Factories\PokerActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $hand_id
 * @property int $user_id
 * @property string $street
 * @property string $action
 * @property int $amount
 * @property int|null $raise_to
 * @property string $idempotency_key
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PokerAction extends Model
{
    public const ACTION_POST_BLIND = 'post_blind';

    public const ACTION_CHECK = 'check';

    public const ACTION_CALL = 'call';

    public const ACTION_BET = 'bet';

    public const ACTION_RAISE = 'raise';

    public const ACTION_FOLD = 'fold';

    public const ACTION_ALL_IN = 'all_in';

    /** @use HasFactory<PokerActionFactory> */
    use HasFactory;

    protected $fillable = [
        'hand_id',
        'user_id',
        'street',
        'action',
        'amount',
        'raise_to',
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
            'raise_to' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PokerHand, $this>
     */
    public function hand(): BelongsTo
    {
        return $this->belongsTo(PokerHand::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBettingAction(): bool
    {
        return in_array($this->action, [
            self::ACTION_POST_BLIND,
            self::ACTION_BET,
            self::ACTION_RAISE,
            self::ACTION_CALL,
            self::ACTION_ALL_IN,
        ], true);
    }
}
