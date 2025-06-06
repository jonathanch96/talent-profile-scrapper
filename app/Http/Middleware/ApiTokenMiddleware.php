<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $staticToken = env('STATIC_API_TOKEN');

        // Check for API token in headers (try both variations)
        $providedToken = $request->header('X-API-TOKEN');

        // Return error if no token provided
        if (!$providedToken) {
            return response()->json([
                'success' => false,
                'message' => 'API token is required',
                'errors' => ['Missing X-API-TOKEN header']
            ], 401);
        }

        // Return error if token doesn't match
        if ($providedToken !== $staticToken) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API token',
                'errors' => ['Invalid authentication credentials']
            ], 401);
        }

        return $next($request);
    }
}
