<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ActivityReport;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-student daily activity overview for admins and teachers (BR-AT7X2T).
 *
 * Both endpoints take the same filters:
 *   classroom_id  - one classroom (teachers: must be one of theirs)
 *   from, to      - inclusive local days, Y-m-d; omit for all time
 */
class AdminActivityController extends Controller
{
    public function __construct(
        private readonly ActivityReport $report,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/admin/activity — rows for the admin table.
     */
    public function index(Request $request): JsonResponse
    {
        [$users, $from, $to] = $this->resolve($request);

        return response()->json([
            'data' => $this->report->build($users, $from, $to),
            'meta' => [
                'from' => $from,
                'to' => $to,
                'students' => $users->count(),
                'timezone' => (string) config('work.timezone', 'Europe/Prague'),
            ],
        ]);
    }

    /**
     * GET /api/admin/activity/export — the same rows as tab-separated CSV,
     * BOM-prefixed so Excel opens it as UTF-8 (same shape as the ELO and
     * answers exports).
     */
    public function export(Request $request): StreamedResponse
    {
        [$users, $from, $to] = $this->resolve($request);
        $rows = $this->report->build($users, $from, $to);

        $headers = [
            'ID žáka (interní)',
            'Přezdívka',
            'ID třídy (interní)',
            'Den',
            'Čas práce',
            'Čas práce (s)',
            'Počet navštívených kurzů',
            'Počet bloků v kurzech',
            'Počet bloků procvičování',
            'Počet bloků kvíz',
            'Počet chatů',
            'Počet otevřených novinek',
            'Počet úprav avatara (nákupy)',
            'Počet her',
            'XP (aktuální stav)',
        ];

        $suffix = ($request->filled('classroom_id') ? 'trida-'.$request->integer('classroom_id').'-' : '')
            .($from ?? 'vse').'_'.($to ?? now()->format('Y-m-d'));
        $filename = 'aktivita-'.$suffix.'.csv';

        $this->audit->log('export.activity', meta: [
            'from' => $from,
            'to' => $to,
            'student_count' => $users->count(),
            'format' => 'csv',
        ], scope: [
            'classroom_id' => $request->filled('classroom_id') ? $request->integer('classroom_id') : null,
        ]);

        return response()->streamDownload(function () use ($rows, $headers) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers, "\t");

            foreach ($rows as $r) {
                fputcsv($handle, array_map([$this, 'csvCell'], [
                    $r['user_id'],
                    $r['user_name'],
                    implode(';', $r['classroom_ids'] ?? []),
                    $r['day'],
                    $this->formatDuration($r['work_seconds']),
                    $r['work_seconds'],
                    $r['courses_visited'],
                    $r['blocks_visited'],
                    $r['practice_blocks'],
                    $r['quiz_blocks'],
                    $r['chats'],
                    $r['news_opened'],
                    $r['avatar_purchases'],
                    $r['games'],
                    $r['xp_points'],
                ]), "\t");
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Validate filters and pick the students in scope.
     *
     * @return array{0: Collection<int, User>, 1: ?string, 2: ?string}
     */
    private function resolve(Request $request): array
    {
        $validated = $request->validate([
            'classroom_id' => 'sometimes|nullable|integer|exists:classrooms,id',
            'from' => 'sometimes|nullable|date_format:Y-m-d',
            'to' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $classroomId = isset($validated['classroom_id']) ? (int) $validated['classroom_id'] : null;

        $query = User::query()
            ->where('role', 'student')
            ->orderBy('id');

        // Teacher scoping: only students in their classrooms, and an explicit
        // classroom filter must be one of theirs rather than silently empty.
        if ($request->attributes->get('admin_role') === 'teacher') {
            $ownIds = $request->user()->teachingClassrooms()->pluck('classrooms.id');

            if ($classroomId !== null && ! $ownIds->contains($classroomId)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Access denied. This classroom is not yours.',
                ], 403));
            }

            $query->inClassrooms($ownIds);
        }

        if ($classroomId !== null) {
            $query->inClassrooms([$classroomId]);
        }

        return [
            $query->with('classrooms:classrooms.id')->get(['id', 'name']),
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        ];
    }

    private function formatDuration(int $seconds): string
    {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private function csvCell($value): string
    {
        return str_replace(["\t", "\r", "\n"], ' ', (string) $value);
    }
}
