<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * Audit row for an account being switched off or back on (BR-Q3ZJ6Q).
 * Written by User::deactivate() / reactivate() and nowhere else.
 */
class UserAccountEvent extends Model
{
    public const UPDATED_AT = null;

    public const DEACTIVATED = 'deactivated';
    public const REACTIVATED = 'reactivated';

    protected $fillable = [
        'user_id',
        'performed_by',
        'event',
        'ip',
        'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public static function record(User $user, string $event, ?User $performedBy, ?Request $request = null): self
    {
        $userAgent = $request?->userAgent();

        return static::create([
            'user_id' => $user->id,
            'performed_by' => $performedBy?->id,
            'event' => $event,
            'ip' => $request?->ip(),
            'user_agent' => $userAgent !== null && mb_check_encoding($userAgent, 'UTF-8')
                ? mb_substr(str_replace("\0", '', $userAgent), 0, 255, 'UTF-8')
                : null,
        ]);
    }
}
