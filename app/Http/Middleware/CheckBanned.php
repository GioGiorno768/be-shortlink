<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class CheckBanned
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->is_banned) {
            $user = Auth::user();

            return response()->json([
                'message' => 'Your account has been suspended.',
                'error' => 'Account Banned',
                'ban_reason' => $user->ban_reason ?? 'Pelanggaran Terms of Service',
            ], 403);
        }

        return $next($request);
    }
}
