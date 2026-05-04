<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SsrfGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ProxyImageController extends Controller
{
    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB
    private const TIMEOUT_SECONDS = 10;

    /**
     * Image proxy for Flutter web (CORS bypass).
     * Hardened against SSRF: scheme allowlist, DNS-resolved private IP block,
     * size cap, content-type allowlist, no upstream redirects.
     *
     * GET /api/proxy/image?url=<https-url>
     */
    public function show(Request $request)
    {
        $url = $request->query('url');

        if (!is_string($url) || strlen($url) > 2048) {
            return response()->json(['error' => 'Invalid url'], 400);
        }

        $check = SsrfGuard::validate($url, allowedSchemes: ['https']);
        if (!$check['ok']) {
            return response()->json(['error' => 'url_rejected', 'reason' => $check['reason']], 400);
        }

        $parsed = parse_url($url);
        $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'EduAI-ImageProxy/1.0',
                'Referer' => $origin . '/',
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*;q=0.8',
            ])
                ->withoutRedirecting()
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url);
        } catch (\Throwable) {
            return response()->json(['error' => 'fetch_failed'], 502);
        }

        if (!$response->successful()) {
            return response()->json(['error' => 'upstream_error', 'status' => $response->status()], 502);
        }

        $contentType = strtolower($response->header('Content-Type') ?? '');
        if (!str_starts_with($contentType, 'image/')) {
            return response()->json(['error' => 'not_an_image'], 400);
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            return response()->json(['error' => 'too_large'], 413);
        }

        return response($body, 200)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'public, max-age=3600')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
