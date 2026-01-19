<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class UpdateUserActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();
            
            // Throttling: Update only if last update was > 5 minutes ago
            // or if last_active_at is null
            if (!$user->last_active_at || $user->last_active_at->diffInMinutes(now()) > 5) {
                $user->forceFill([
                    'last_active_at' => now(),
                ])->save();
            }
        }

        return $next($request);
    }
}
