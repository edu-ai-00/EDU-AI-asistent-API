<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DashboardBanner;
use App\Services\AuditLogger;
use App\Support\DashboardBannerLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard banners in the admin (BR-SAWX2U).
 *
 * CRUD over the banners the app's dashboard shows, with the same shape as the
 * notification admin: Czech messages, every write audited, seeded system
 * banners editable and deactivatable but not deletable (NOTIF-9 G).
 */
class AdminDashboardBannerController extends Controller
{
    private const AUDITED = [
        'name', 'is_active', 'position', 'badge', 'title', 'body', 'accent',
        'illustration', 'link', 'target_role', 'min_xp', 'max_xp',
        'course_id', 'classroom_id', 'school_id',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/admin/dashboard-banners?active=
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'active' => 'sometimes|nullable|boolean',
        ]);

        $query = DashboardBanner::query()->orderBy('position')->orderBy('id');

        if (isset($validated['active']) && $validated['active'] !== '') {
            $query->where('is_active', filter_var($validated['active'], FILTER_VALIDATE_BOOLEAN));
        }

        $banners = $query->get();

        return response()->json([
            'data' => $banners->map(fn (DashboardBanner $banner) => $this->bannerJson($banner))->values(),
            'meta' => [
                'total' => $banners->count(),
                'accents' => DashboardBanner::ACCENTS,
                'illustrations' => DashboardBanner::ILLUSTRATIONS,
                'roles' => DashboardBanner::ROLES,
                'links' => DashboardBannerLink::catalog(),
            ],
        ]);
    }

    /**
     * GET /api/admin/dashboard-banners/{id}
     */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->bannerJson(DashboardBanner::findOrFail($id))]);
    }

    /**
     * POST /api/admin/dashboard-banners
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request, required: true);

        $banner = new DashboardBanner($validated + [
            'key' => null,
            'is_system' => false,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);
        DB::transaction(function () use ($banner) {
            $banner->save();

            $this->audit->log(
                'dashboard_banner.created',
                $banner,
                diff: AuditLogger::created($banner, self::AUDITED),
            );
        });

        return response()->json([
            'message' => 'Banner vytvořen.',
            'data' => $this->bannerJson($banner),
        ], 201);
    }

    /**
     * PUT /api/admin/dashboard-banners/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $banner = DashboardBanner::findOrFail($id);
        $validated = $this->validated($request, required: false, existing: $banner);

        $wasActive = $banner->is_active;

        $banner->fill($validated);
        $banner->updated_by = $request->user()?->id;
        $banner->save();

        $action = match (true) {
            ! $wasActive && $banner->is_active => 'dashboard_banner.activated',
            $wasActive && ! $banner->is_active => 'dashboard_banner.deactivated',
            default => 'dashboard_banner.updated',
        };

        $this->audit->log($action, $banner, diff: AuditLogger::changes($banner));

        return response()->json([
            'message' => 'Banner uložen.',
            'data' => $this->bannerJson($banner),
        ]);
    }

    /**
     * DELETE /api/admin/dashboard-banners/{id}
     *
     * A seeded banner is kept and deactivated instead — it is what the app
     * showed before the admin could touch it, and losing it would leave no
     * way back to the original dashboard.
     */
    public function destroy(int $id): JsonResponse
    {
        $banner = DashboardBanner::findOrFail($id);

        if ($banner->is_system) {
            throw ValidationException::withMessages([
                'id' => 'Systémový banner nelze smazat, jen vypnout.',
            ]);
        }

        DB::transaction(function () use ($banner) {
            $this->audit->log(
                'dashboard_banner.deleted',
                $banner,
                diff: AuditLogger::created($banner, self::AUDITED),
            );

            $banner->delete();
        });

        return response()->json(['message' => 'Banner smazán.']);
    }

    /**
     * The writable fields. `required` is off on an update so a partial save
     * leaves the rest of the banner alone.
     */
    private function validated(Request $request, bool $required, ?DashboardBanner $existing = null): array
    {
        $req = $required ? 'required' : 'sometimes|required';

        $validated = $request->validate([
            'name' => "{$req}|string|max:255",
            'title' => "{$req}|string|max:255",
            'body' => "{$req}|string|max:2000",
            'is_active' => 'sometimes|boolean',
            'position' => 'sometimes|integer|min:0|max:65535',
            'badge' => 'sometimes|nullable|string|max:32',
            'accent' => ['sometimes', Rule::in(DashboardBanner::ACCENTS)],
            'illustration' => ['sometimes', 'nullable', Rule::in(DashboardBanner::ILLUSTRATIONS)],
            'link' => 'sometimes|nullable|string|max:255',
            'target_role' => ['sometimes', Rule::in(DashboardBanner::ROLES)],
            'min_xp' => 'sometimes|nullable|integer|min:0',
            'max_xp' => 'sometimes|nullable|integer|min:0',
            'course_id' => 'sometimes|nullable|integer|exists:courses,id',
            'classroom_id' => 'sometimes|nullable|integer|exists:classrooms,id',
            'school_id' => 'sometimes|nullable|integer|exists:schools,id',
        ]);

        if (array_key_exists('link', $validated) && ! DashboardBannerLink::isValid($validated['link'])) {
            throw ValidationException::withMessages([
                'link' => 'Tento odkaz aplikace neumí otevřít.',
            ]);
        }

        // Checked here rather than with `gte` so the message names the fields
        // the admin sees, and so a partial save compares against what is stored:
        // a lone min_xp/max_xp still gets weighed against the banner's other end.
        $min = array_key_exists('min_xp', $validated) ? $validated['min_xp'] : $existing?->min_xp;
        $max = array_key_exists('max_xp', $validated) ? $validated['max_xp'] : $existing?->max_xp;
        if ($min !== null && $max !== null && $max < $min) {
            throw ValidationException::withMessages([
                'max_xp' => 'Horní hranice XP nesmí být nižší než dolní.',
            ]);
        }

        if (array_key_exists('link', $validated) && trim((string) $validated['link']) === '') {
            $validated['link'] = null;
        }

        return $validated;
    }

    private function bannerJson(DashboardBanner $banner): array
    {
        return [
            'id' => $banner->id,
            'key' => $banner->key,
            'name' => $banner->name,
            'is_active' => $banner->is_active,
            'is_system' => $banner->is_system,
            'position' => $banner->position,
            'badge' => $banner->badge,
            'title' => $banner->title,
            'body' => $banner->body,
            'accent' => $banner->accent,
            'illustration' => $banner->illustration,
            'link' => $banner->link,
            'target_role' => $banner->target_role,
            'min_xp' => $banner->min_xp,
            'max_xp' => $banner->max_xp,
            'course_id' => $banner->course_id,
            'classroom_id' => $banner->classroom_id,
            'school_id' => $banner->school_id,
            'created_at' => $banner->created_at?->toIso8601String(),
            'updated_at' => $banner->updated_at?->toIso8601String(),
        ];
    }
}
