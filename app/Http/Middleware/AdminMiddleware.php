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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Check if user is authenticated
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Check if user is admin or super_admin
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized. Admin access only.'], 403);
        }

        // Check if admin is banned/suspended
        if ($user->is_banned) {
            return response()->json([
                'message' => 'Your admin account has been suspended.',
                'error' => 'Account Suspended',
                'ban_reason' => $user->ban_reason ?? 'Contact super admin for details.',
                'suspended' => true,
            ], 403);
        }

        return $next($request);
    }
}
