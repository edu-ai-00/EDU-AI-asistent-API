<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * A chat mentor — what the API still calls a "persona" on the wire.
 *
 * chat_sessions.persona stores the slug, so slugs are the stable identifier;
 * renaming one orphans every session that used it.
 */
class Mentor extends Model
{
    use HasFactory;

    /** Answered by ChatAiService (OpenRouter) using ai_context. */
    public const ENGINE_LLM = 'llm';

    /** Answered by RagSearchService against rag_legal_entity. */
    public const ENGINE_RAG = 'rag';

    public const ENGINES = [self::ENGINE_LLM, self::ENGINE_RAG];

    /** Offered in the new-chat mentor list. */
    public const VISIBILITY_PUBLIC = 'public';

    /** Not listed; reachable only from inside its bound course. */
    public const VISIBILITY_HIDDEN = 'hidden';

    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_HIDDEN];

    /** Slug lookups happen on every chat message; cache them briefly. */
    private const CACHE_TTL = 300;

    protected $fillable = [
        'slug',
        'name',
        'subtitle',
        'description',
        'emoji',
        'avatar_url',
        'engine',
        'ai_context',
        'rag_legal_entity',
        'rag_domains',
        'visibility',
        'course_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'course_id' => 'integer',
            'rag_domains' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (Mentor $m) => $m->forgetCached());
        static::deleted(fn (Mentor $m) => $m->forgetCached());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Lookups
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Resolve a mentor by the slug stored in chat_sessions.persona.
     *
     * Inactive mentors still resolve: existing sessions must keep answering
     * with the right engine and prompt after a mentor is retired — only the
     * "start a new chat" lists filter on is_active.
     */
    public static function findBySlug(?string $slug): ?self
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return Cache::remember(
            self::cacheKey($slug),
            self::CACHE_TTL,
            fn () => static::where('slug', $slug)->first(),
        );
    }

    private function forgetCached(): void
    {
        Cache::forget(self::cacheKey($this->slug));

        // A slug rename leaves the old key pointing at this row.
        $original = $this->getOriginal('slug');
        if ($original && $original !== $this->slug) {
            Cache::forget(self::cacheKey($original));
        }
    }

    private static function cacheKey(string $slug): string
    {
        return 'mentor:slug:' . $slug;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════════════════

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Mentors a user may start a new chat with: everything public, plus the
     * hidden ones bound to a course they are enrolled in.
     */
    public function scopeSelectableBy(Builder $query, User $user): Builder
    {
        return $query->active()->where(function (Builder $q) use ($user) {
            $q->where('visibility', self::VISIBILITY_PUBLIC)
                ->orWhere(function (Builder $q) use ($user) {
                    $q->where('visibility', self::VISIBILITY_HIDDEN)
                        ->whereNotNull('course_id')
                        ->whereIn('course_id', UserCourse::where('user_id', $user->id)->select('course_id'));
                });
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════════

    public function isRag(): bool
    {
        return $this->engine === self::ENGINE_RAG;
    }

    public function isHidden(): bool
    {
        return $this->visibility === self::VISIBILITY_HIDDEN;
    }

    /**
     * Whose documents this mentor searches. Falls back to the global env so a
     * RAG mentor left unconfigured behaves exactly as before this table.
     */
    public function legalEntity(): string
    {
        return $this->rag_legal_entity ?: (string) config('rag.legal_entity');
    }

    /**
     * The RAG `domains` filter narrowing this mentor inside its legal entity
     * (BR-DAB49K) — e.g. ["SECONDARY_ADMISSIONS"] keeps Cermatík on the
     * admissions documents while Školník, on the same entity, sees them all.
     * Empty = no filter; the caller then leaves `domains` out of the request.
     *
     * @return list<string>
     */
    public function ragDomains(): array
    {
        return array_values(array_filter(
            array_map(fn ($d) => trim((string) $d), (array) ($this->rag_domains ?? [])),
            fn (string $d) => $d !== '',
        ));
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
