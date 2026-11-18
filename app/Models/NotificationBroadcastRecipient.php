<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One addressee in a broadcast's recipient snapshot. user_id is a plain id
 * (no foreign key) so the snapshot outlives a deleted account.
 */
class NotificationBroadcastRecipient extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'broadcast_id',
        'user_id',
        'role',
        'school_id',
        'classroom_id',
    ];

    protected function casts(): array
    {
        return [
            'broadcast_id' => 'integer',
            'user_id' => 'integer',
            'school_id' => 'integer',
            'classroom_id' => 'integer',
        ];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(NotificationBroadcast::class, 'broadcast_id');
    }
}
