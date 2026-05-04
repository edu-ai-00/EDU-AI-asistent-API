<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMerge extends Model
{
    /**
     * Audit trail row written for every UserMerge operation.
     *
     * Single created_at column only — there is no update flow for a merge.
     */
    public $timestamps = false;

    protected $table = 'user_merges';

    protected $fillable = [
        'source_user_id',
        'target_user_id',
        'performed_by',
        'source_snapshot',
        'target_before',
        'strategy_log',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'source_snapshot' => 'array',
            'target_before' => 'array',
            'strategy_log' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    public function source(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
