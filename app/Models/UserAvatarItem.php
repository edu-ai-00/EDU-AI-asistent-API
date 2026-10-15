<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One acquired cosmetic. Default items are *not* recorded here — they are
 * implicit, so a fresh account owns its starting outfit with zero rows.
 */
class UserAvatarItem extends Model
{
    protected $fillable = [
        'user_id',
        'avatar_item_id',
        'price_paid',
        'acquired_at',
    ];

    protected function casts(): array
    {
        return [
            'price_paid' => 'integer',
            'acquired_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function avatarItem(): BelongsTo
    {
        return $this->belongsTo(AvatarItem::class);
    }
}
