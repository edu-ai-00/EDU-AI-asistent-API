<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GamificationConfig;
use Illuminate\Http\JsonResponse;

class GamificationConfigController extends Controller
{
    /**
     * Get the latest gamification config.
     *
     * GET /api/gamification/config
     */
    public function show(): JsonResponse
    {
        $config = GamificationConfig::orderBy('version', 'desc')->first();

        if (!$config) {
            return response()->json([
                'version' => 0,
                'updated_at' => null,
                'levels' => [],
                'trophies' => [],
                'goals' => [],
                'challenges' => [],
            ]);
        }

        $data = $config->config;
        $data['version'] = $config->version;
        $data['updated_at'] = $config->updated_at->toIso8601String();

        return response()->json($data);
    }
}
