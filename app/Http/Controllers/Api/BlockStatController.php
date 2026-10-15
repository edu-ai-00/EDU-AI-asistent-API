<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockStat;
use App\Services\TaskCalibrationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockStatController extends Controller
{
    /**
     * Calibrated task difficulty for the app (BR-697NCA).
     *
     * Filters: ?course_id=X (the course's blocks — what the app pulls per
     * downloaded course), ?block_ids=id1,id2, ?updated_since=ISO (inclusive:
     * rows saved in the same second as the cursor come again rather than
     * never).
     *
     * `elo_vector` is the calibrated D per dimension, null where no answer
     * has calibrated it yet — the app takes the course's own elo_vector
     * there. While ELO_TASK_CALIBRATION is off nothing is published
     * (`meta.task_calibration: false`, no rows), so the app drops what it
     * cached and every consumer falls back to the course content.
     *
     * GET /api/blocks/stats
     */
    public function index(Request $request, TaskCalibrationService $calibration): JsonResponse
    {
        if (! TaskCalibrationService::enabled()) {
            return response()->json([
                'data' => [],
                'meta' => ['task_calibration' => false],
            ]);
        }

        return $this->respond($request, $calibration, true);
    }

    /**
     * The same rows for the admin, published or not — rollout checks the
     * calibrated values before switching the calibration on.
     *
     * GET /api/admin/blocks/stats
     */
    public function adminIndex(Request $request, TaskCalibrationService $calibration): JsonResponse
    {
        return $this->respond($request, $calibration, TaskCalibrationService::enabled());
    }

    private function respond(Request $request, TaskCalibrationService $calibration, bool $enabled): JsonResponse
    {
        $request->validate([
            'course_id' => 'nullable|string|max:255',
            'block_ids' => 'nullable|string',
            'updated_since' => 'nullable|date',
        ]);

        $query = BlockStat::query();

        if ($request->filled('course_id')) {
            $query->whereIn('block_id', $calibration->courseBlockIds($request->input('course_id')));
        }

        if ($request->filled('block_ids')) {
            $blockIds = explode(',', $request->input('block_ids'));
            $query->whereIn('block_id', $blockIds);
        }

        if ($request->filled('updated_since')) {
            $query->where(
                'updated_at',
                '>=',
                Carbon::parse($request->input('updated_since'))->setTimezone(config('app.timezone'))
            );
        }

        $stats = $query->orderBy('block_id')->get();

        return response()->json([
            'data' => $stats->map(fn ($s) => [
                'block_id' => $s->block_id,
                'elo_vector' => $s->elo_vector,
                'item_pocet' => $s->item_pocet,
                'updated_at' => $s->updated_at->toIso8601String(),
            ]),
            'meta' => ['task_calibration' => $enabled],
        ]);
    }
}
