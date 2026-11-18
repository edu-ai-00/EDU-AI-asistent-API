<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkHeartbeat;
use App\Notifications\NotificationProducers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WorkHeartbeatController extends Controller
{
    /**
     * Ingest a batch of activity heartbeats from the client.
     *
     * Idempotent on (user_id, client_uuid): re-syncing the same rows is a
     * no-op. Timestamps in the future (client clock skew) are clamped to now.
     *
     * `platform` / `device_type` describe the device sending the batch — the
     * local queue only ever holds that device's own heartbeats — and are
     * stamped on every row (BR-Y2TJ74). Older app builds omit them. Free-form
     * on purpose: a value this server does not know must not bounce the whole
     * batch and lose the work time with it.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => 'nullable|string|max:16',
            'device_type' => 'nullable|string|max:16',
            'heartbeats' => 'required|array|min:1|max:1000',
            'heartbeats.*.client_uuid' => 'required|uuid',
            'heartbeats.*.course_id' => 'required|string|max:255',
            'heartbeats.*.lesson_id' => 'nullable|string|max:255',
            'heartbeats.*.occurred_at' => 'required|date',
        ]);

        $userId = $request->user()->id;
        $now = Carbon::now();
        $tolerance = $now->copy()->addMinutes(5);
        $platform = $validated['platform'] ?? null;
        $deviceType = $validated['device_type'] ?? null;

        $rows = [];
        foreach ($validated['heartbeats'] as $entry) {
            $occurredAt = Carbon::parse($entry['occurred_at']);
            // Clamp implausible future timestamps to now.
            if ($occurredAt->greaterThan($tolerance)) {
                $occurredAt = $now->copy();
            }

            $rows[] = [
                'user_id' => $userId,
                'client_uuid' => $entry['client_uuid'],
                'course_id' => $entry['course_id'],
                'lesson_id' => $entry['lesson_id'] ?? null,
                'platform' => $platform,
                'device_type' => $deviceType,
                'occurred_at' => $occurredAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Idempotent insert: existing (user_id, client_uuid) rows are left
        // untouched, new ones inserted.
        WorkHeartbeat::upsert(
            $rows,
            ['user_id', 'client_uuid'],
            ['course_id', 'lesson_id', 'platform', 'device_type', 'occurred_at', 'updated_at'],
        );

        // Learning activity (target action) and the break suggestion once
        // the current session runs long (study.long_session).
        NotificationProducers::heartbeatsReceived($request->user());

        return response()->json(['accepted' => count($rows)]);
    }
}
