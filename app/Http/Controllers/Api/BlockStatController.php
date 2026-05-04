<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockStat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockStatController extends Controller
{
    /**
     * Get block stats.
     * Supports optional filters: ?block_ids=id1,id2,id3
     *
     * GET /api/blocks/stats
     */
    public function index(Request $request): JsonResponse
    {
        $query = BlockStat::query();

        if ($request->filled('block_ids')) {
            $blockIds = explode(',', $request->input('block_ids'));
            $query->whereIn('block_id', $blockIds);
        }

        $stats = $query->orderBy('block_id')->get();

        return response()->json([
            'data' => $stats->map(fn ($s) => [
                'block_id' => $s->block_id,
                'item_pocet' => $s->item_pocet,
                'updated_at' => $s->updated_at->toIso8601String(),
            ]),
        ]);
    }
}
