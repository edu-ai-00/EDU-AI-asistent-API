<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only User Merge endpoints (SPEC §5.3).
 *
 *   GET  /api/admin/users/merge/preview  → dry-run, returns plan
 *   POST /api/admin/users/merge          → executes the merge
 *
 * The `admin` middleware applied at the route level already restricts
 * access to admin/teacher; we additionally require `isAdmin()` here
 * because merging is admin-only (teachers cannot merge accounts).
 */
class AdminUserMergeController extends Controller
{
    public function __construct(private readonly UserMergeService $service)
    {
    }

    /**
     * GET /api/admin/users/merge/preview
     */
    public function preview(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return response()->json([
                'message' => 'Access denied. Admin role required.',
            ], 403);
        }

        $validated = $request->validate([
            'source_id' => 'required|integer|exists:users,id',
            'target_id' => 'required|integer|exists:users,id',
        ]);

        $plan = $this->service->preview(
            (int) $validated['source_id'],
            (int) $validated['target_id'],
        );

        return response()->json([
            'data' => $plan,
        ]);
    }

    /**
     * POST /api/admin/users/merge
     */
    public function merge(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return response()->json([
                'message' => 'Access denied. Admin role required.',
            ], 403);
        }

        $validated = $request->validate([
            'source_id' => 'required|integer|exists:users,id',
            'target_id' => 'required|integer|exists:users,id',
        ]);

        $audit = $this->service->merge(
            (int) $validated['source_id'],
            (int) $validated['target_id'],
            (int) $request->user()->id,
        );

        return response()->json([
            'data' => [
                'merge_id' => $audit->id,
                'source_user_id' => $audit->source_user_id,
                'target_user_id' => $audit->target_user_id,
                'performed_by' => $audit->performed_by,
                'strategy_log' => $audit->strategy_log,
                'created_at' => $audit->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Resolve admin status from either the admin middleware's role attribute
     * or the user's role directly. X-Admin-Key sets admin_role=admin without
     * an authenticated user; in that case there's no acting admin user, so we
     * accept the header-based admin flag too.
     */
    private function isAdmin(Request $request): bool
    {
        if ($request->attributes->get('admin_role') === 'admin') {
            return true;
        }

        $user = $request->user();
        return $user !== null && $user->isAdmin();
    }
}
