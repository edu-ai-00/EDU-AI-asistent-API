<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\WorkTimeService;
use Illuminate\Http\JsonResponse;

class AdminWorkTimeController extends Controller
{
    public function __construct(private readonly WorkTimeService $service)
    {
    }

    /**
     * Active work-time report for one student, shown in the admin student
     * detail view (BR-9SAH2R): totals per day, per course, lifetime, and a
     * recent session list.
     */
    public function show(int $userId): JsonResponse
    {
        // 404 if the student does not exist.
        User::query()->findOrFail($userId);

        return response()->json([
            'data' => $this->service->forUser($userId),
        ]);
    }
}
