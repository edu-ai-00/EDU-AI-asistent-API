<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DashboardBanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard banners the app should consider showing (BR-SAWX2U).
 *
 * Returns every active banner whose course / class / school / role
 * conditions the user meets, in render order, each carrying its XP band. The
 * band is the app's to evaluate: the device's XP is authoritative between
 * syncs, and the banner has to follow it the instant it moves.
 */
class DashboardBannerController extends Controller
{
    /**
     * GET /api/dashboard/banners
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $banners = DashboardBanner::query()->forUser($user)->get();

        return response()->json([
            'data' => $banners->map(fn (DashboardBanner $banner) => $banner->toAppJson())->values(),
        ]);
    }
}
