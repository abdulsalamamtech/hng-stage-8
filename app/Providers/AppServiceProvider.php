<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        Sanctum::getAccessTokenFromRequestUsing(function (Request $request) {
            // Attempt to retrieve the token from the 'X-Api-Key' header
            $apiKey = $request->header('X-Api-Key');

            // bearer token
            $token = $request->bearerToken();

            info('Full Request Headers: ' . json_encode($request->headers->all()));
            info('API Key from X-Api-Key header: ' . $apiKey);
            info('Bearer Token: ' . $token);

            return $apiKey ?? $token;
        });
    }
}
