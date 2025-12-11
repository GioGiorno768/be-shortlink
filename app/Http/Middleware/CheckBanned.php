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
            // Optional: Logout user to invalidate session/token
            // Auth::guard('web')->logout(); 
            // $request->session()->invalidate();
            // $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Your account has been suspended. Please contact support.',
                'error' => 'Account Banned'
            ], 403);
        }

        return $next($request);
    }
}
