<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiOrSanctumAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

        // Try authenticating with sanctum first
        // If sanctum fails, try authenticating with the 'api' guard
        if (Auth::guard('sanctum')->check() || Auth::guard('api')->check()) {
            return $next($request);
        }

        // If neither works, return an unauthorized response
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated.'
        ], 401);        
        // return $next($request);
    }
}
