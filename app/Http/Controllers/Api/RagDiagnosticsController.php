<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RagSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RagDiagnosticsController extends Controller
{
    /**
     * Probe the RAG search service step by step (config → health → entities,
     * plus a real /ask with ?ask=1) so an operator can tell whether a chat
     * failure is our configuration or the provider's service/edge.
     *
     * ?base_url= overrides the configured base URL for this probe only, so
     * scheme/path variants the provider suggests can be tested from the API's
     * whitelisted egress without a redeploy. Restricted to the RAG host.
     *
     * GET /api/admin/rag/health[?ask=1][&base_url=...]
     */
    public function show(Request $request, RagSearchService $rag): JsonResponse
    {
        $baseUrl = $request->query('base_url');
        if ($baseUrl !== null) {
            $host = parse_url($baseUrl, PHP_URL_HOST);
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
            if (!in_array($scheme, ['http', 'https'], true) || !preg_match('/^rag\d*\.edu-ai\.eu$/', (string) $host)) {
                return response()->json(['message' => 'base_url must point at a rag*.edu-ai.eu host'], 422);
            }

            $rag = new RagSearchService(
                baseUrl: $baseUrl,
                apiKey: config('rag.api_key'),
                timeout: (int) config('rag.timeout'),
                legalEntity: (string) config('rag.legal_entity'),
                cfAccessClientId: config('rag.cf_access_client_id'),
                cfAccessClientSecret: config('rag.cf_access_client_secret'),
            );
        }

        return response()->json([
            'data' => $rag->diagnose($request->boolean('ask')),
        ]);
    }
}
