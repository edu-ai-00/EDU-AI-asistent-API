<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rule bookkeeping per user: the weekly XP goal baseline (week_key is the
 * ISO week, week_start_xp the lifetime XP when that week was first seen).
 */
class NotificationUserState extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'week_key',
        'week_start_xp',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'week_start_xp' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
