<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use function Laravel\Prompts\info;

class KeyController extends Controller
{

    // listKeys
    public function listKeys(Request $request)
    {
        $user = $request->user();

        $tokens = $user->tokens()->get()->map(function ($token) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'token' => $token->token,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ];
        });

        info('listKeys called by user id: ' . $user->id);
        return response()->json([
            "success" => true,
            'message' => 'List Keys called',
            'data' => $tokens
        ]);
    }

    // Revoke a specific API key
    public function revoke(Request $request, $tokenId)
    {
        info('Revoke called for token id: ' . $tokenId . ' by user id: ' . $request->user()->id);
        // Find the token belonging to the authenticated user
        $result = $request->user()->tokens()
            ->where('id', $tokenId)
            ->delete(); // Revokes the token instantly

        if (!$result) {
            return response()->json([
                "success" => false,
                'message' => 'API Key not found or does not belong to the user'
            ]);
        }

        return response()->json([
            "success" => true,
            'message' => 'API Key revoked successfully'
        ]);
    }

    // Protect this route with the JWT user token
    public function create(Request $request)
    {

        $user = $request->user();
        // 1. Max 5 active keys check
        if ($user->tokens()->whereNull('expires_at')->count() >= 5) {
            return response()->json(['error' => 'Maximum 5 active API keys allowed.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:read,deposit,transfer,balance,withdraw',
            'expiry' => 'nullable|string|in:1H,1D,1M,1Y',
        ]);

        // 2. Permission and Expiry Parsing (simplified)
        $permissions = $request->input('permissions', [
            'read', // balance access by default
            'deposit',
            'transfer',
            'balance', // same as read
            'withdraw',
        ]);

        info('permissions: ' . json_encode($permissions));

        $expiryInput = $request->input('expiry', '1Y'); // e.g., 1D, 1M, 1Y

        $expires_at = match ($expiryInput) {
            '1H' => now()->addHour(),
            '1D' => now()->addDay(),
            '1M' => now()->addMonth(),
            '1Y' => now()->addYear(),
            default => null, // Default to no expiry or handle error
        };

        // 3. Create Token (Sanctum)
        $tokenResult = $user->createToken(
            $request->name,
            $permissions,
            $expires_at
        );

        return response()->json([
            "success" => true,
            'id' => $tokenResult->accessToken->id,
            'api_key' => $tokenResult->plainTextToken,
            'expires_at' => $expires_at,
        ]);
    }
}
