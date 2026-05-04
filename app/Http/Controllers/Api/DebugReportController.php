<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DebugReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DebugReportController extends Controller
{
    /**
     * Store a debug report from the app.
     *
     * POST /api/debug-reports
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'app_version'  => 'required|string|max:50',
            'platform'     => 'required|string|max:20',
            'os_version'   => 'nullable|string|max:100',
            'device_model' => 'nullable|string|max:100',
            'report_data'  => 'required|array',
        ]);

        $report = DebugReport::create([
            'user_id' => $request->user()?->id,
            ...$validated,
        ]);

        return response()->json([
            'id' => $report->id,
            'status' => 'received',
        ], 201);
    }

    /**
     * List debug reports (admin).
     * Supports ?user_id= filter.
     * Excludes report_data from listing (it's large).
     *
     * GET /api/admin/debug-reports
     */
    public function index(Request $request): JsonResponse
    {
        $query = DebugReport::with('user:id,name,email');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $reports = $query->orderBy('created_at', 'desc')->paginate(50);

        return response()->json([
            'data' => collect($reports->items())->map(fn ($r) => [
                'id' => $r->id,
                'user' => $r->user ? [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                    'email' => $r->user->email,
                ] : null,
                'app_version' => $r->app_version,
                'platform' => $r->platform,
                'os_version' => $r->os_version,
                'device_model' => $r->device_model,
                'created_at' => $r->created_at->toIso8601String(),
            ]),
            'meta' => [
                'total' => $reports->total(),
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
            ],
        ]);
    }

    /**
     * Get a single debug report with full data (admin).
     *
     * GET /api/admin/debug-reports/{id}
     */
    public function show(int $id): JsonResponse
    {
        $report = DebugReport::with('user:id,name,email')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $report->id,
                'user' => $report->user ? [
                    'id' => $report->user->id,
                    'name' => $report->user->name,
                    'email' => $report->user->email,
                ] : null,
                'app_version' => $report->app_version,
                'platform' => $report->platform,
                'os_version' => $report->os_version,
                'device_model' => $report->device_model,
                'report_data' => $report->report_data,
                'created_at' => $report->created_at->toIso8601String(),
            ],
        ]);
    }
}
