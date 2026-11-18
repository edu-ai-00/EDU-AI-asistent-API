<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * An FCM registration token, owned by one user and bound to the Sanctum
 * session that registered it (see the push_devices migration for why).
 */
class PushDevice extends Model
{
    public const PLATFORMS = ['android', 'ios', 'macos'];

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'personal_access_token_id',
        'app_version',
        'last_seen_at',
        'disabled_at',
        'disabled_reason',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'personal_access_token_id' => 'integer',
            'last_seen_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->whereNull('disabled_at');
    }

    /** FCM said the token is dead — stop sending to it. */
    public function disable(string $reason): void
    {
        $this->forceFill([
            'disabled_at' => now(),
            'disabled_reason' => mb_substr($reason, 0, 255),
        ])->save();
    }

    /**
     * A session token was rotated: the devices it registered now belong to
     * the new one, so they survive the old token's deletion.
     */
    public static function repointToken(int $fromTokenId, int $toTokenId): int
    {
        if ($fromTokenId === $toTokenId) {
            return 0;
        }

        return self::where('personal_access_token_id', $fromTokenId)
            ->update(['personal_access_token_id' => $toTokenId]);
    }
}
