<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A banner the admin places on the app's dashboard (BR-SAWX2U).
 *
 * Standing placement, not a delivered notification: the app asks for the
 * matching banners and re-evaluates the XP band on every dashboard build, so
 * one appears and disappears as the learner's XP moves. See the
 * create_dashboard_banners_table migration for why the XP band is evaluated
 * on the device and the rest server-side.
 */
class DashboardBanner extends Model
{
    /** Palette keys the app knows how to render. */
    public const ACCENTS = ['primary', 'orange', 'green', 'purple'];

    /** Illustrations shipped with the app build. */
    public const ILLUSTRATIONS = ['news'];

    public const ROLES = ['student', 'teacher', 'all'];

    protected $fillable = [
        'key',
        'name',
        'is_active',
        'is_system',
        'position',
        'badge',
        'title',
        'body',
        'accent',
        'illustration',
        'link',
        'target_role',
        'min_xp',
        'max_xp',
        'course_id',
        'classroom_id',
        'school_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'position' => 'integer',
            'min_xp' => 'integer',
            'max_xp' => 'integer',
            'course_id' => 'integer',
            'classroom_id' => 'integer',
            'school_id' => 'integer',
        ];
    }

    /**
     * The active banners this user can be shown, in render order.
     *
     * Every condition that is set must hold (AND); a null one is no
     * constraint. The XP band is deliberately not applied here — it rides
     * along in the payload for the app to evaluate against its local XP.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->where('target_role', 'all')
                ->orWhereIn('target_role', self::rolesMatching($user->role)))
            ->where(fn (Builder $q) => $q
                ->whereNull('course_id')
                ->orWhereIn('course_id', function ($sub) use ($user) {
                    // Any enrollment counts, including the placeholder row a
                    // class assignment leaves behind (BR-9F9VV9) — the course
                    // is in the learner's list either way.
                    $sub->select('course_id')->from('user_courses')->where('user_id', $user->id);
                }))
            ->where(fn (Builder $q) => $q
                ->whereNull('classroom_id')
                ->orWhereIn('classroom_id', function ($sub) use ($user) {
                    $sub->select('classroom_id')->from('classroom_student')->where('user_id', $user->id);
                }))
            ->where(fn (Builder $q) => $q
                ->whereNull('school_id')
                ->orWhereIn('school_id', self::schoolIdsOf($user)))
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * The `target_role` values a user of this role falls under.
     *
     * Mirrors NotificationType::roleMatches — an admin is on the team, so a
     * teacher-facing banner reaches them too.
     *
     * @return list<string>
     */
    public static function rolesMatching(?string $userRole): array
    {
        return match ($userRole) {
            'student' => ['student'],
            'teacher', 'admin' => ['teacher'],
            default => [],
        };
    }

    /**
     * Schools the user belongs to: their classes' schools for a student, the
     * schools they are filed under for a teacher or admin.
     *
     * @return list<int>
     */
    public static function schoolIdsOf(User $user): array
    {
        if ($user->role === 'student') {
            return Classroom::query()
                ->whereIn('id', function ($sub) use ($user) {
                    $sub->select('classroom_id')->from('classroom_student')->where('user_id', $user->id);
                })
                ->whereNotNull('school_id')
                ->pluck('school_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return $user->schools()->pluck('schools.id')->map(fn ($id) => (int) $id)->all();
    }

    /** What the app reads. */
    public function toAppJson(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'badge' => $this->badge,
            'title' => (string) $this->title,
            'body' => (string) $this->body,
            'accent' => $this->accent,
            'illustration' => $this->illustration,
            'link' => $this->link,
            'min_xp' => $this->min_xp,
            'max_xp' => $this->max_xp,
        ];
    }
}
