<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AppleIdTokenVerifier;
use App\Services\GoogleIdTokenVerifier;
use App\Services\MicrosoftIdTokenVerifier;
use App\Services\RagSearchService;
use App\Support\WorkSessionizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Work-time tracking: build the sessionizer from config tuning so the
        // report/service/backfill all share the same gap + cap (BR-9SAH2R).
        $this->app->singleton(WorkSessionizer::class, function () {
            return new WorkSessionizer(
                sessionGapSeconds: (int) config('work.session_gap_seconds', 1800),
                activeCapSeconds: (int) config('work.active_cap_seconds', 90),
            );
        });

        // Google id_token verifier, seeded with the configured client IDs.
        // Bound so feature tests can swap in a fake without hitting Google.
        $this->app->bind(GoogleIdTokenVerifier::class, function () {
            return new GoogleIdTokenVerifier((array) config('services.google.client_ids', []));
        });

        $this->app->bind(AppleIdTokenVerifier::class, function () {
            return new AppleIdTokenVerifier((array) config('services.apple.client_ids', []));
        });

        $this->app->bind(MicrosoftIdTokenVerifier::class, function () {
            return new MicrosoftIdTokenVerifier(
                (array) config('services.microsoft.client_ids', []),
                (string) config('services.microsoft.tenant', 'common'),
            );
        });

        // RAG search client (Školník / Cermatík chat personas). Bound so
        // feature tests can swap in a fake without hitting the service.
        $this->app->bind(RagSearchService::class, function () {
            return new RagSearchService(
                baseUrl: (string) config('rag.base_url'),
                apiKey: config('rag.api_key'),
                timeout: (int) config('rag.timeout'),
                legalEntity: (string) config('rag.legal_entity'),
                cfAccessClientId: config('rag.cf_access_client_id'),
                cfAccessClientSecret: config('rag.cf_access_client_secret'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Deactivation revokes the account's tokens (User::deactivate()); this
        // is the backstop for one minted in the same instant — a login already
        // past its check when the admin clicked. A deactivated account's
        // bearer token authenticates nobody, so the API answers 401
        // (BR-Q3ZJ6Q).
        Sanctum::authenticateAccessTokensUsing(
            fn ($accessToken, bool $isValid) => $isValid
                && ! ($accessToken->tokenable instanceof User && $accessToken->tokenable->isDeactivated()),
        );

        // Nightwatch attaches the signed-in user to every request, exception
        // and log. By default that is the user's name and email — for most of
        // our users a child's. Team accounts keep theirs (an admin hitting a
        // 500 is someone to call); everyone else is sent as id + role only,
        // which is enough to look them up in the admin and keeps student PII
        // out of a third-party store the erasure flow cannot reach.
        Nightwatch::user(fn (User $user) => $user->isAdminOrTeacher()
            ? ['name' => (string) $user->name, 'username' => (string) $user->email]
            : ['name' => $user->is_guest ? 'guest' : ($user->is_minor ? 'student (minor)' : 'student'), 'username' => '']);

        RateLimiter::for('chat-messages', function (Request $request) {
            return Limit::perMinute(20)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many messages. Please wait.',
                        'retry_after' => 30,
                    ], 429);
                });
        });

        // Login code resolution (student login codes / course PINs) — abuse prevention.
        RateLimiter::for('code-resolve', function (Request $request) {
            return [
                Limit::perMinute(5)->by('code-resolve:ip:'.$request->ip()),
                Limit::perHour(30)->by('code-resolve:ip:'.$request->ip()),
            ];
        });

        // Joining a class by student code from an existing account
        // (BR-3V2P22) — same six-character secret space as code-resolve, so
        // the same brute-force budget, keyed by account as well as by IP.
        RateLimiter::for('classroom-join', function (Request $request) {
            $who = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(5)->by('classroom-join:ip:'.$request->ip()),
                Limit::perHour(30)->by('classroom-join:ip:'.$request->ip()),
                Limit::perHour(30)->by('classroom-join:user:'.$who),
            ];
        });

        // Email code verification — guard against brute-force of 6-digit codes.
        RateLimiter::for('email-verify', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            return [
                Limit::perMinute(5)->by('email-verify:ip:'.$request->ip()),
                Limit::perMinute(5)->by('email-verify:email:'.$email),
                Limit::perHour(20)->by('email-verify:email:'.$email),
            ];
        });

        // Email code send — IP-side limit (per-email already in controller).
        RateLimiter::for('email-send-code', function (Request $request) {
            return [
                Limit::perMinute(3)->by('email-send:ip:'.$request->ip()),
                Limit::perHour(20)->by('email-send:ip:'.$request->ip()),
            ];
        });

        // Admin auth send/verify.
        RateLimiter::for('admin-auth-send', function (Request $request) {
            return [
                Limit::perMinute(3)->by('admin-send:ip:'.$request->ip()),
                Limit::perHour(20)->by('admin-send:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('admin-auth-verify', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            return [
                Limit::perMinute(5)->by('admin-verify:ip:'.$request->ip()),
                Limit::perMinute(5)->by('admin-verify:email:'.$email),
                Limit::perHour(20)->by('admin-verify:email:'.$email),
            ];
        });

        // OAuth id_token exchange — IP-bound; verification is cheap but guard
        // against brute-force/replay bursts.
        RateLimiter::for('oauth', function (Request $request) {
            return [
                Limit::perMinute(10)->by('oauth:ip:'.$request->ip()),
                Limit::perHour(60)->by('oauth:ip:'.$request->ip()),
            ];
        });

        // Image proxy — IP-bound; intentionally low.
        RateLimiter::for('image-proxy', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Open-answer AI grading (BR-FFVVYR) — every call costs LLM tokens, so
        // this is a cost guard as much as an abuse guard. Sized for a student
        // working through a lesson, not for scripted traffic.
        //
        // Unaffected by the trustProxies() problem described below: the route
        // is behind auth:sanctum, so the user id is always present and the ip()
        // fallback never fires. This is a genuine per-caller limit.
        RateLimiter::for('answer-eval', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(15)->by('answer-eval:min:'.$key),
                Limit::perHour(200)->by('answer-eval:hour:'.$key),
            ];
        });

        // End-of-quiz AI comment ("Quiz Feedback v1"). One call per finished
        // block of ten, so the honest ceiling is low — this guards LLM cost
        // against a scripted client, not real students.
        RateLimiter::for('quiz-feedback', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(6)->by('quiz-feedback:min:'.$key),
                Limit::perHour(60)->by('quiz-feedback:hour:'.$key),
            ];
        });

        // Photographed answers (BR-P745Q8). Separate bucket from the grading
        // limit on purpose: a photo costs an R2 write, not LLM tokens, and a
        // student retaking a blurry shot three times must not eat into the
        // budget that decides whether they can get feedback at all.
        RateLimiter::for('answer-photo', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(10)->by('answer-photo:min:'.$key),
                Limit::perHour(120)->by('answer-photo:hour:'.$key),
            ];
        });

        // NOTE: these IP-keyed limits are only as good as $request->ip().
        // bootstrap/app.php does not configure trustProxies(), so behind the
        // Railway edge every caller currently shares one bucket. Tracked
        // separately — do not assume the per-IP limit works in production yet.
        // The per-IP number below is deliberately loose (not a real per-caller
        // limit) for two reasons: (1) with every caller sharing one bucket, a
        // tight IP limit is a global kill switch for the whole feature, not a
        // per-abuser throttle; (2) even with a correct client IP, a school
        // computer lab has thirty children entering thirty different parent
        // emails from one address in a burst. Tighten this once trustProxies()
        // is configured — the per-email limit is what actually matters here,
        // since it works regardless of the proxy problem.
        // Parental consent request — 3/h per email, 60/h per IP (BR-8FDF37).
        RateLimiter::for('consent-request', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perHour(3)->by('consent-request:email:'.$email),
                Limit::perHour(60)->by('consent-request:ip:'.$request->ip()),
            ];
        });

        // Consent confirmation — the per-code attempt budget lives on the row;
        // this only stops someone spraying codes across many consents.
        RateLimiter::for('consent-confirm', function (Request $request) {
            return [
                Limit::perMinute(5)->by('consent-confirm:ip:'.$request->ip()),
                Limit::perHour(20)->by('consent-confirm:ip:'.$request->ip()),
            ];
        });

        // Guardian magic-link management page.
        RateLimiter::for('consent-manage', function (Request $request) {
            return Limit::perMinute(10)->by('consent-manage:ip:'.$request->ip());
        });

        // Phone capture for the AI Course Assistant (BR-D4PE8R). Unauthenticated
        // by design — the QR session token is the credential.
        //
        // Same trustProxies() caveat as the consent limits above: until it is
        // configured, every caller shares one bucket. That is tolerable here
        // because the thing worth throttling is token guessing, and a shared
        // bucket still caps guesses globally. The cost is that one abuser can
        // lock everyone else out of the capture page — annoying, not dangerous,
        // and the desktop upload path keeps working regardless.
        RateLimiter::for('generation-capture', function (Request $request) {
            return [
                Limit::perMinute(20)->by('gen-capture:ip:'.$request->ip()),
                Limit::perHour(120)->by('gen-capture:ip:'.$request->ip()),
            ];
        });
    }
}
