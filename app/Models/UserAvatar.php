<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's currently worn outfit: slot => item code.
 *
 * One row per user. Absent means "never customised" — the client then falls
 * back to the default items, so there is nothing to backfill for existing
 * accounts.
 */
class UserAvatar extends Model
{
    protected $fillable = [
        'user_id',
        'equipped',
    ];

    protected function casts(): array
    {
        return [
            'equipped' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The outfit a user gets before they touch the shop.
     *
     * @return array<string, string|null>
     */
    public static function defaultEquipped(): array
    {
        return AvatarItem::query()
            ->active()
            ->defaults()
            ->orderBy('layer')
            ->pluck('code', 'slot')
            ->all();
    }
}
