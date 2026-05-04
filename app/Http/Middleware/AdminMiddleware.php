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

        // 2) X-Admin-Key header → full admin access (only when no authenticated user with a role)
        $adminKey = $request->header('X-Admin-Key');
        $validAdminKey = config('admin.api_key');

        if ($adminKey && $validAdminKey && hash_equals($validAdminKey, $adminKey)) {
            $request->attributes->set('admin_role', 'admin');
            return $next($request);
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

        return response()->json([
            'message' => 'Access denied. Insufficient privileges.',
        ], 403);
    }
}
