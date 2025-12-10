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

// key 
Route::prefix('keys')->middleware(['auth:api'])->controller(KeyController::class)->group(function () {
    Route::post('/', 'createKey')->middleware('auth:api'); // Create API Key (Protected by JWT)
    Route::get('/', 'listKeys')->middleware('auth:api'); // List API Keys (Protected by JWT)
    Route::delete('/{keyId}', 'revokeKey')->middleware('auth:api'); // Revoke API Key (Protected by JWT)
        Route::post('create', 'create');
        // rollback permission checks for API keys
        Route::get('/', 'list')->middleware('can:read');
        Route::delete('/{tokenId}revoke', 'revoke');
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
Route::middleware(['auth:api', 'auth.api_or_sanctum'])->group(function () {

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


// Route::middleware(['auth.api_or_sanctum'])->get('/both', function () {
Route::middleware(['auth:sanctum', 'can:read'])->get('/both', function () {

    return response()->json([
        'message' => 'Accessed with API Key or JWT',
        'user' => request()->user(),
    ]);
});
