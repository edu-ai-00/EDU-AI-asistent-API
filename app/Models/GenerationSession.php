<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A short-lived AI Course Assistant session (BR-D4PE8R).
 *
 * The plaintext token is returned exactly once, when the session is issued; only
 * its SHA-256 hash is persisted. Anyone holding the token may upload
 * attachments to this one session until it expires — nothing else.
 */
class GenerationSession extends Model
{
    public const STATUS_COLLECTING = 'collecting';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public const MODE_OCR = 'ocr';
    public const MODE_FULL = 'full';

    protected $fillable = [
        'token_hash',
        'course_id',
        'course_name',
        'status',
        'mode',
        'attachments',
        'result',
        'error',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Issue a new session. Returns the model plus the one-time plaintext token.
     *
     * @return array{session: self, token: string}
     */
    public static function issue(?string $courseId, ?string $courseName): array
    {
        // Opportunistic cleanup: production runs neither a queue worker nor a
        // scheduler, so expired sessions are pruned whenever a new one is made.
        self::pruneExpired();

        $token = Str::random(48);

        $session = self::create([
            'token_hash' => hash('sha256', $token),
            'course_id' => $courseId,
            'course_name' => $courseName,
            'status' => self::STATUS_COLLECTING,
            'attachments' => [],
            'expires_at' => Carbon::now()->addMinutes(config('generation.ttl_minutes')),
        ]);

        return ['session' => $session, 'token' => $token];
    }

    /**
     * Resolve a plaintext token to a live session, or null if unknown/expired.
     */
    public static function findByToken(string $token): ?self
    {
        $session = self::where('token_hash', hash('sha256', $token))->first();

        if (! $session || $session->isExpired()) {
            return null;
        }

        return $session;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Append an uploaded file to the session's attachment list.
     *
     * @param  array<string, mixed>  $meta
     */
    public function addAttachment(array $meta): void
    {
        $attachments = $this->attachments ?? [];
        $attachments[] = $meta;
        $this->update(['attachments' => $attachments]);
    }

    /**
     * Storage prefix for this session's uploads.
     */
    public function storagePrefix(): string
    {
        return "generation/{$this->id}";
    }

    /**
     * Delete every uploaded file of this session from R2.
     */
    public function purgeAttachments(): void
    {
        foreach ($this->attachments ?? [] as $attachment) {
            if (! empty($attachment['path'])) {
                Storage::disk('s3')->delete($attachment['path']);
            }
        }

        $this->update(['attachments' => []]);
    }

    /**
     * Delete expired sessions and everything they uploaded.
     */
    public static function pruneExpired(): int
    {
        $expired = self::where('expires_at', '<', Carbon::now())->get();

        foreach ($expired as $session) {
            try {
                $session->purgeAttachments();
            } catch (\Throwable) {
                // R2 hiccup must not block pruning the DB row.
            }
            $session->delete();
        }

        return $expired->count();
    }
}
