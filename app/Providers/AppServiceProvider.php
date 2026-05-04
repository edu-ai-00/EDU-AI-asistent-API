<?php

namespace App\Providers;

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
        //
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

        // Image proxy — IP-bound; intentionally low.
        RateLimiter::for('image-proxy', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
