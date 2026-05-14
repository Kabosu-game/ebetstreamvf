<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InternalTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $internalHeader = $request->header('X-Internal-Request');

        if ($internalHeader !== '1') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $configuredToken = config('app.internal_token');

        if ($configuredToken) {
            $sentToken = $request->header('Authorization');
            if ($sentToken !== 'Bearer ' . $configuredToken) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }
        }

        return $next($request);
    }
}
