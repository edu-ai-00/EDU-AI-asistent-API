<?php

namespace App\Providers;

use App\Services\AppleIdTokenVerifier;
use App\Services\GoogleIdTokenVerifier;
use App\Services\MicrosoftIdTokenVerifier;
use App\Support\WorkSessionizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}
