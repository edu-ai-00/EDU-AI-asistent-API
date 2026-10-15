<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Accepts:
     * 1. X-Admin-Key header → treated as role=admin
     * 2. Sanctum-authenticated user with matching role
     *
     * Usage in routes:
     *   middleware('admin')              → admin only
     *   middleware('admin:admin,teacher') → admin or teacher
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Default to admin-only when no roles specified
        if (empty($roles)) {
            $roles = ['admin'];
        }

        // 1) Sanctum-authenticated user with a role — always use their actual role
        $user = $request->user('sanctum');

        if ($user && in_array($user->role, $roles)) {
            $request->attributes->set('admin_role', $user->role);
            // Set the resolved user on the default guard so $request->user() works in controllers
            auth()->setUser($user);
            return $next($request);
        }

        // 2) X-Admin-Key header → full admin access, but only for callers that
        //    bring no user credentials of their own (scripts, server-to-server).
        //
        //    It stands in for an operator; it must never *upgrade* one. The
        //    admin panel proxies the key next to the user's bearer token on
        //    every request (eduai-admin lib/server/auth.ts), and admin/teacher
        //    tokens expire after 8 hours while the panel's session cookie lives
        //    for 7 days with nothing refreshing it. So once the token died, the
        //    key used to answer for it and a teacher went on browsing as a full
        //    admin — which is how classes from other schools turned up in a
        //    teacher's list, `is_own` true on every one of them (BR-4U7XUW).
        $hasBearerToken = $request->bearerToken() !== null;

        if (!$hasBearerToken) {
            $adminKey = $request->header('X-Admin-Key');
            $validAdminKey = config('admin.api_key');

            if ($adminKey && $validAdminKey && hash_equals($validAdminKey, $adminKey)) {
                $request->attributes->set('admin_role', 'admin');
                return $next($request);
            }
        }

        // 3) Legacy fallback: check ADMIN_EMAILS config (for existing admin tokens)
        $user = $user ?? $request->user();
        if ($user) {
            $adminEmails = array_filter(array_map(
                'trim',
                explode(',', config('admin.emails', ''))
            ));

            if (in_array($user->email, $adminEmails) && in_array('admin', $roles)) {
                $request->attributes->set('admin_role', 'admin');
                return $next($request);
            }
        }

        // A bearer token that resolved to nobody is an expired or revoked
        // session, not an anonymous caller. Saying 401 rather than 403 is what
        // makes the admin panel clear the cookie and go back to the login
        // screen (eduai-admin lib/api/client.ts) instead of keeping a dead
        // session alive for the rest of the cookie's week.
        if ($hasBearerToken && !$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'message' => 'Access denied. Insufficient privileges.',
        ], 403);
    }
}
