<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\ConsentAuditLog;
use App\Models\ConsentCode;
use App\Models\Guardian;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Guardian self-service behind a magic link. There is no account and no
 * password here — possession of the emailed token is the whole authorisation.
 */
class ConsentManageController extends Controller
{
    public function __construct(private readonly ConsentService $consents) {}

    /** Resolve a magic-link token to its consent, or null when it is dead. */
    private function resolve(string $token, ?Request $request = null): ?Consent
    {
        $row = ConsentCode::findLiveManageToken($token);

        if (! $row) {
            return null;
        }

        $consent = $row->consent;

        if (! $consent || $consent->isRevoked()) {
            return null;
        }

        // The token lives in the URL path, so it reaches access logs and stays
        // there long after its 24h TTL. That is an accepted trade-off (see
        // decision 11), and this event is what makes misuse detectable after
        // the fact — without it a stolen link leaves no trace until it is used
        // to revoke something.
        ConsentAuditLog::record($consent, 'link_opened', $request);

        return $consent;
    }

    /**
     * Every response from this controller carries the token (or proof it was
     * once valid) in its URL, so none of them may be cached or leaked via a
     * referrer header — the compensation decision 11 promises for keeping
     * the token in the URL path rather than a fragment.
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders([
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * GET /api/consent/manage/{token}
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $consent = $this->resolve($token, $request);

        if (! $consent) {
            return $this->json(['message' => 'Odkaz vypršel nebo byl použit.'], 404);
        }

        $children = User::where('guardian_id', $consent->guardian_id)
            ->orderBy('id')
            ->get(['id', 'name', 'created_at'])
            ->map(fn (User $child) => [
                'id' => $child->id,
                'name' => $child->name,
                'created_at' => $child->created_at?->toIso8601String(),
            ]);

        return $this->json([
            'status' => $consent->status,
            'confirmed_at' => $consent->confirmed_at?->toIso8601String(),
            'text_version' => $consent->text_version,
            'children' => $children,
        ]);
    }

    /**
     * POST /api/consent/manage/{token}/regenerate
     *
     * The token is resolved before the payload is validated, so a dead or
     * expired token always yields 404 — never a 422 that would leak that the
     * token was live but the request body was malformed.
     */
    public function regenerate(Request $request, string $token): JsonResponse
    {
        $consent = $this->resolve($token, $request);

        if (! $consent) {
            return $this->json(['message' => 'Odkaz vypršel nebo byl použit.'], 404);
        }

        $validated = $request->validate(['child_id' => 'required|integer']);

        $child = User::where('id', $validated['child_id'])
            ->where('guardian_id', $consent->guardian_id)
            ->where('is_minor', true)
            ->first();

        if (! $child) {
            return $this->json(['message' => 'Účet nenalezen.'], 404);
        }

        return $this->json([
            'code' => $this->consents->regenerateChildCode($consent, $child, $request),
        ]);
    }

    /**
     * POST /api/consent/manage/{token}/revoke
     */
    public function revoke(Request $request, string $token): JsonResponse
    {
        $consent = $this->resolve($token, $request);

        if (! $consent) {
            return $this->json(['message' => 'Odkaz vypršel nebo byl použit.'], 404);
        }

        $this->consents->revoke($consent, $request);

        return $this->json([
            'revoked' => true,
            'purge_after_days' => (int) config('consent.purge_after_days'),
        ]);
    }

    /**
     * POST /api/consent/manage/request-link
     *
     * Constant response — must not reveal whether the address is known.
     * sendManageLink() rethrows on a mail failure precisely so this method
     * can catch it here and keep the response identical either way — the
     * same shape as ConsentController::request().
     */
    public function requestLink(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:255']);

        $guardian = Guardian::where('email', strtolower(trim($validated['email'])))->first();
        $consent = $guardian?->activeConsent();

        if ($consent) {
            try {
                $this->consents->sendManageLink($consent, $request);
            } catch (Throwable $e) {
                // Report but keep the response shape constant — a 500 here
                // would itself leak that something about this address is
                // different. Same PII discipline as ConsentController::
                // request(): no message, no email, just enough to alert on
                // and to locate the matching report() entry.
                report($e);
                Log::critical('consent.manage.request_link.failed', [
                    'exception_class' => get_class($e),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }

        return $this->json(['sent' => true]);
    }
}
