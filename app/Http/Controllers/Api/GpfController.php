<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SkillVector;
use Illuminate\Http\JsonResponse;

class GpfController extends Controller
{
    /**
     * Canonical GPF vector dimension labels for the app.
     *
     * Returns the 35 dimensions of the default public GPF vector (Czech
     * labels) so the Flutter client can render skill cards without hardcoding
     * label strings. Read-only reference data; available to any logged-in or
     * guest user.
     *
     * GET /api/gpf/dimensions
     */
    public function dimensions(): JsonResponse
    {
        $vector = SkillVector::public()
            ->orderBy('created_at')
            ->first();

        $dimensions = $vector
            ? $vector->dimensions()->orderBy('dimension_index')->get()
            : collect();

        return response()->json([
            'data' => $dimensions->map(fn ($d) => [
                'dimension_index' => $d->dimension_index,
                'code' => $d->code,
                'domain_code' => $d->domain_code,
                'domain_name' => $d->domain_name,
                'construct_name' => $d->construct_name,
                'name' => $d->name,
            ])->values(),
        ]);
    }
}
