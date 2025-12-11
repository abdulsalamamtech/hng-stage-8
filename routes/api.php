<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\KeyController;

use App\Http\Controllers\WalletController;
use App\Http\Controllers\ApiKeyController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

Route::get('/user', function (Request $request) {
    return response()->json([
        "success" => true,
        'message' => 'Accessed with API Key (Service)',
        'user' => $request->user(),
        'token_id' => $request->user()->currentAccessToken()->id
    ]);
})->middleware('auth:sanctum');




// 1. User Authentication (JWT)
// Route::prefix('auth')->group(function () {
//     // public routes
//     Route::post('signup', [AuthController::class, 'signup']);
//     Route::post('login', [AuthController::class, 'login']);
//     // Protected by the JWT guard
//     Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
// });

// key 
Route::prefix('keys')->middleware(['auth:api'])->controller(KeyController::class)->group(function () {
    Route::get('/', 'listKeys'); // List API Keys (Protected by JWT)
    Route::delete('/{tokenId}/revoke', 'revoke');
    Route::post('create', 'create');
});



// 3. Protected Routes
// a) User-only access (Protected by JWT)
Route::middleware('auth:api')->get('/user-resource', function (Request $request) {
    return response()->json([
        'message' => 'Accessed with JWT (User)',
        'user' => $request->user(),
        'token_expires' => auth()->guard('api')->payload()->get('exp'),

    ]);
});


// b) Service-to-Service access (Protected by Sanctum/API Key)
Route::middleware('auth:sanctum')->get('/service-resource', function (Request $request) {
    // Optional: Check for the specific ability assigned when the key was created
    if (!$request->user()->tokenCan('service:access')) {
        return response()->json([
            "success" => false,
            'error' => 'Key lacks required ability'
        ], 403);
    }
    return response()->json([
        "success" => true,
        'message' => 'Accessed with API Key (Service)',
        'user' => $request->user(),
        'token_id' => $request->user()->currentAccessToken()->id
    ]);
});


// 1. Google Auth (No middleware needed)
Route::controller(AuthController::class)->prefix('auth')->group(function () {
    Route::get('google', 'redirectToGoogle');
    Route::get('google/callback', 'handleGoogleCallback');
});

// 2. Paystack Webhook (No standard auth, uses signature validation)
// Route::post('wallet/paystack/webhook', [WalletController::class, 'handlePaystackWebhook']);

// 3. Protected Routes (Use auth:sanctum which handles both JWT and API Key)
Route::middleware(['auth.api_or_sanctum'])->group(function () {

    // Wallet Endpoints (Requires permission checks for API keys)
    Route::controller(WalletController::class)->prefix('wallet')->group(function () {
        // Check for 'deposit' permission for API Keys
        // Route::post('deposit', 'deposit')->middleware('ability:deposit');
        Route::post('deposit', 'deposit');
        Route::get('deposit/{reference}/status', 'verifyDepositStatus');

        // Check for 'transfer' permission for API Keys
        // Route::post('transfer', 'transfer')->middleware('ability:transfer');
        Route::post('transfer', 'transfer');

        // Check for 'read' permission for API Keys
        // Route::get('balance', 'getBalance')->middleware('ability:read');
        Route::get('balance', 'getBalance');

        // Route::get('transactions', 'getTransactions')->middleware('ability:read');
        Route::get('transactions', 'getTransactions');
    });
});



// paystack webhook
Route::post('wallet/paystack/webhook', [WalletController::class, 'handleWebhook']);
// verify transaction status
Route::get('wallet/paystack/webhook', [WalletController::class, 'verifyPayment']);


Route::middleware(['auth.api_or_sanctum'])->get('/both', function (Request $request) {
    // Route::middleware(['auth:sanctum'])->get('/both', function (Request $request) {

    // $user = Auth::guard('sanctum')->user() ?? Auth::guard('api')->user();
    $user = $request->user();

    // check if the request doesn't use jwt or api key before proceeding
    if (Auth::guard('sanctum')->check() && auth('sanctum')?->user()?->tokenCant('read')) {
        Log::warning('Unauthorized read attempt by user ID: ' . $request?->user()?->id);
        return response()->json([
            'success' => false,
            'message' => 'You lack the required permission to perform this action.',
        ]);
    } else {
        Log::info('Authorized read access by user ID: ' . $request?->user()?->id);
        // proceed with jwt user
        return response()->json([
            'message' => 'Accessed with JWT',
            'user' => $user,
        ]);
    }


    return response()->json([
        'message' => 'Accessed with API Key or JWT',
        'user' => $user,
    ]);
});
