<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\KeyController;

use App\Http\Controllers\WalletController;
use App\Http\Controllers\ApiKeyController;

Route::get('/user', function (Request $request) {
    return response()->json([
        "success" => true,
        'message' => 'Accessed with API Key (Service)',
        'user' => $request->user(),
        'token_id' => $request->user()->currentAccessToken()->id
    ]);
})->middleware('auth:sanctum');




// 1. User Authentication (JWT)
Route::prefix('auth')->group(function () {
    // public routes
    Route::post('signup', [AuthController::class, 'signup']);
    Route::post('login', [AuthController::class, 'login']);
    // Protected by the JWT guard
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
});

// 2. API Key Management (Protected by JWT, used by a User)
Route::middleware('auth:api')->prefix('keys')->group(function () {
    Route::post('create', [KeyController::class, 'create']);
    Route::delete('{tokenId}/revoke', [KeyController::class, 'revoke']);
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
Route::post('wallet/paystack/webhook', [WalletController::class, 'handlePaystackWebhook']);

// 3. Protected Routes (Use auth:sanctum which handles both JWT and API Key)
Route::middleware(['auth:api'])->group(function () {

    // API Key Management (User access only - implied by auth:sanctum user model)
    Route::prefix('keys')->controller(KeyController::class)->group(function () {
        Route::post('create', 'create');
        Route::post('rollover', 'rollover'); // Implement logic in controller
        Route::delete('{tokenId}/revoke', 'revoke'); // Implement logic in controller
    });

    // Wallet Endpoints (Requires permission checks for API keys)
    Route::controller(WalletController::class)->prefix('wallet')->group(function () {
        // Check for 'deposit' permission for API Keys
        // Route::post('deposit', 'deposit')->middleware('ability:deposit');
        Route::post('deposit', 'deposit');
        Route::get('deposit/{reference}/status', 'verifyDepositStatus');

        // Check for 'transfer' permission for API Keys
        Route::post('transfer', 'transfer')->middleware('ability:transfer');

        // Check for 'read' permission for API Keys
        Route::get('balance', 'getBalance')->middleware('ability:read');
        Route::get('transactions', 'getTransactions')->middleware('ability:read');
    });
});



// paystack webhook
Route::post('wallet/paystack/webhook', [WalletController::class, 'handlePaystackWebhook']);
Route::get('wallet/paystack/webhook', [WalletController::class, 'verifyPayment']);

