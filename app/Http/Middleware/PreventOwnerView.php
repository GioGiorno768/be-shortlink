<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Link;

class PreventOwnerView
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->route('code');

        if ($code) {
            $link = Link::where('short_code', $code)->first();

            // Jika user login dan dia adalah pemilik link
            if ($link && auth()->check() && auth()->id() === $link->user_id) {
                return response()->json([
                    'message' => 'You are the owner of this link. Views are not counted.',
                    'original_url' => $link->original_url,
                    'skip_view' => true
                ], 200);
            }
        }

        return $next($request);
    }
}
