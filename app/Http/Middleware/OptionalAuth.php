<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class OptionalAuth
{
    /**
     * Handle an incoming request.
     *
     * Attempts to authenticate via Sanctum if a token is present,
     * but allows the request to proceed even without authentication.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Try to authenticate via Sanctum if Authorization header is present
        if ($request->bearerToken()) {
            Auth::shouldUse('sanctum');
        }

        // Resolve admin_role so controllers (e.g. CourseController::index)
        // can distinguish admin / teacher / student on public-read routes.

        // 1) Sanctum-authenticated user with a known role
        $user = $request->user('sanctum');
        if ($user && in_array($user->role, ['admin', 'teacher'])) {
            $request->attributes->set('admin_role', $user->role);
            $request->attributes->set('is_admin', $user->role === 'admin');
            return $next($request);
        }

        // 2) X-Admin-Key header → full admin access
        $adminKey = $request->header('X-Admin-Key');
        $validAdminKey = config('admin.api_key');
        if ($adminKey && $validAdminKey && hash_equals($validAdminKey, $adminKey)) {
            $request->attributes->set('admin_role', 'admin');
            $request->attributes->set('is_admin', true);
        }

        return $next($request);
    }
}
