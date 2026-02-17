<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InternalTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->header('Authorization');
        $expected = 'Bearer ' . config('app.internal_token');

        // Vérifier aussi le header custom
        $internalHeader = $request->header('X-Internal-Request');

        if ($token !== $expected || $internalHeader !== '1') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        return $next($request);
    }
}
