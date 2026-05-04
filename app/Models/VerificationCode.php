<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationCode extends Model
{
    protected $fillable = [
        'email',
        'code',
        'expires_at',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * Check if the code has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the code has been verified.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Mark the code as verified.
     */
    public function markAsVerified(): void
    {
        $this->update(['verified_at' => now()]);
    }

    /**
     * Generate a new verification code for an email.
     */
    public static function generateFor(string $email, int $expiresInMinutes = 10): self
    {
        // Delete any existing unverified codes for this email
        static::where('email', $email)
            ->whereNull('verified_at')
            ->delete();

        return static::create([
            'email' => $email,
            'code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'expires_at' => now()->addMinutes($expiresInMinutes),
        ]);
    }

    /**
     * Find a valid (not expired, not verified) code for an email.
     */
    public static function findValidCode(string $email, string $code): ?self
    {
        return static::where('email', $email)
            ->where('code', $code)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Count codes sent to an email in the last hour (for rate limiting).
     */
    public static function countRecentCodes(string $email, int $minutes = 60): int
    {
        return static::where('email', $email)
            ->where('created_at', '>', now()->subMinutes($minutes))
            ->count();
    }
}
