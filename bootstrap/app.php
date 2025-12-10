<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {


        // Custom middleware
        $middleware->alias([
            'auth.api_or_sanctum' => \App\Http\Middleware\EnsureApiOrSanctumAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Start of render customized error message
        $exceptions->render(function (Throwable $e, Request $request) {

            // Log::info('Request:', $request->all() ?? $request->getContent());
            // Log::info('Raw Input: ' . $request->getContent());
            Log::error('Error:', [$e?->getMessage(), $e?->getTraceAsString()]);

            // Working with API requests
            if ($request->is('api/*')) {

                // Custom response for all exceptions
                $response = [
                    'success' => false,
                    'message' => 'An error occurred. Please try again later.',
                    // Avoid exposing error details in production
                    'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error',
                ];

                // Set a default status code
                $statusCode = 500;

                // Customize response for different exception types
                switch (true) {
                    case $e instanceof ValidationException:
                        $response['message'] = 'Validation failed, please check your input.';
                        $response['errors'] = $e->errors();
                        $statusCode = 422;
                        break;
                    case $e instanceof AuthenticationException:
                        $response['message'] = 'Unauthenticated. Please log in.';
                        $statusCode = 401;
                        break;
                    case $e instanceof AuthorizationException:
                        $response['message'] = 'Unauthorized. You do not have permission.';
                        $statusCode = 403;
                        break;
                    case $e instanceof ModelNotFoundException:
                        $response['message'] = 'Resource not found.';
                        $statusCode = 404;
                        break;
                    case $e instanceof NotFoundHttpException:
                        $response['message'] = 'Endpoint not found.';
                        $statusCode = 404;
                        break;
                    default:
                        if ($e instanceof \Illuminate\Database\QueryException) {
                            $response['message'] = 'Database error occurred. Please check your request.';
                            $statusCode = 500;
                        }
                }

                $response['status'] = $statusCode;

                return response()->json($response, $statusCode);
            }
        });
        // End of render customized error message        
    })->create();
