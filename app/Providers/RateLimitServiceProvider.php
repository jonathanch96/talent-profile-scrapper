<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        RateLimiter::for('talent-api', function (Request $request) {
            return [
                // Allow 60 requests per minute per IP
                Limit::perMinute(60)->by($request->ip()),

                // Allow 1000 requests per hour per API token
                Limit::perHour(1000)->by($request->header('X-API-TOKEN') ?: $request->header('X-API_TOKEN') ?: 'anonymous'),
            ];
        });

        // Optional: More restrictive rate limit for write operations
        RateLimiter::for('talent-api-write', function (Request $request) {
            return [
                // Allow 30 writes per minute per IP
                Limit::perMinute(30)->by($request->ip()),

                // Allow 500 writes per hour per API token
                Limit::perHour(500)->by($request->header('X-API-TOKEN') ?: $request->header('X-API_TOKEN') ?: 'anonymous'),
            ];
        });
    }
}
