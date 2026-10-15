<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consents) {}

    /**
     * Age thresholds so the app can render the right gate offline-first.
     *
     * GET /api/consent/config
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'age_thresholds' => (array) config('consent.age_thresholds'),
            'default_age_threshold' => (int) config('consent.default_age_threshold'),
            'text_version' => (string) config('consent.text_version'),
        ]);
    }

    /**
     * Start a consent flow. The response is deliberately constant — it must
     * not reveal whether the email is already known to us. That holds across
     * both branches of ConsentService::requestConsent(): a guardian who has
     * already consented is mailed an overview of their existing accounts
     * instead of a fresh ask (BR-2JW929), and the response body does not
     * change to say so.
     *
     * POST /api/consent/request
     */
    public function request(Request $httpRequest): JsonResponse
    {
        $validated = $httpRequest->validate([
            'email' => 'required|email|max:255',
            'locale' => 'sometimes|string|in:cs,sk,en',
            'country' => 'sometimes|nullable|string|size:2',
        ]);

        try {
            $this->consents->requestConsent(
                email: $validated['email'],
                locale: $validated['locale'] ?? 'cs',
                country: isset($validated['country']) ? strtoupper($validated['country']) : null,
                httpRequest: $httpRequest,
            );
        } catch (\Throwable $e) {
            // Report but keep the response shape constant — a 500 here would
            // itself leak that something about this address is different.
            report($e);

            // report() alone has nothing alerting on it, and the response can
            // never change by design — so a broken mail transport would
            // silently return {"sent":true} to every parent forever with no
            // operational signal. This line is that signal — deliberately not
            // diagnostic: real mailer/SMTP exceptions routinely embed the
            // recipient address in their message text, and this is a feature
            // whose whole point is minimising what we hold about families.
            // No message, no email — just enough to alert on and to locate
            // the matching report() entry by class/code/site.
            Log::critical('consent.request.failed', [
                'exception_class' => get_class($e),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return response()->json(['sent' => true]);
    }

    /**
     * Confirm a consent from the emailed link. Reachable only with the public
     * id from the email plus the typed code — never from a child's session.
     * The response is deliberately identical for every *rejection* reason
     * (wrong code, expired, spent attempt budget, unknown or already-settled
     * consent) — see ConsentService::confirm(). A provisioning failure after
     * a genuinely correct code is not a rejection in that sense — the code
     * is already spent and cannot be retried — but it is reported the same
     * generic way rather than as a distinct 500, so the response never
     * reveals which internal step failed. Ops gets the real signal from
     * report()/Log::critical below and the 'provision_failed' audit row.
     *
     * POST /api/consent/confirm
     */
    public function confirm(Request $httpRequest): JsonResponse
    {
        $validated = $httpRequest->validate([
            'id' => 'required|uuid',
            'code' => 'required|string|size:6',
        ]);

        try {
            $result = $this->consents->confirm($validated['id'], $validated['code'], $httpRequest);
        } catch (\Throwable $e) {
            // Same treatment as request() above, including the same PII
            // discipline: no exception message, no email, just enough to
            // alert on and to locate the matching report() entry.
            report($e);
            Log::critical('consent.confirm.failed', [
                'exception_class' => get_class($e),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'confirmed' => false,
                'message' => 'Kód je neplatný nebo vypršel. Požádejte o nový.',
            ], 422);
        }

        if (! $result) {
            return response()->json([
                'confirmed' => false,
                'message' => 'Kód je neplatný nebo vypršel. Požádejte o nový.',
            ], 422);
        }

        return response()->json([
            'confirmed' => true,
            'children' => $result['children'],
            // Lets the public confirm page tell a parent whose mail failed
            // to expect a resend instead of an inbox that will stay empty —
            // the controller could not otherwise distinguish a sent mail
            // from a failed one, at the exact moment the parent is still on
            // the page and could act on it.
            'mail_sent' => $result['mail_sent'],
        ]);
    }
}
