<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

use Laravel\Socialite\Facades\Socialite;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function signup(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = Auth::login($user); // Login and generate JWT

        return response()->json([
            "success" => true,
            'token' => $token,
            'user' => $user
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!$token = Auth::attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json([
            "success" => true,
            'token' => $token
        ]);
    }

    public function logout()
    {
        Auth::logout(); // Blacklist the current JWT
        return response()->json([
            "success" => true,
            'message' => 'Successfully logged out'
        ]);
    }


    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect(); // for API-based apps
        // return Socialite::driver('google')->redirect(); // for web-based apps
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            // $googleUser = Socialite::driver('google')->user();

            // Debugging info
            info('Google User:', [$googleUser]);

            $user = User::updateOrCreate(
                ['google_id' => $googleUser->id],
                [
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'password' => Hash::make(uniqid()), // Random password
                    'email_verified_at' => now(), // Mark email as verified
                ]
            );

            // Debugging info
            info('User after Google sign-in:', [$user]);

            // Ensure a wallet is created if one doesn't exist
            if (!$user->wallet) {
                $user->wallet()->create(['balance' => 0]);
                // Reload to include the wallet relationship
                $user->load('wallet');
            }

            // Define your custom claims, including abilities
            $customClaims = [
                'abilities' => ['deposit', 'transfer']
            ];

            // Generate and return JWT
            $token = JWTAuth::fromUser($user, $customClaims);
            // $token = Auth::login($user); // Login and generate JWT


            $data = [
                'user' => $user,
                'token' => $token,
                'token_type' => 'bearer',
                // JWT AUTH
                'auth_type' => 'jwt',
                // JWT TTL is in minutes, converting to seconds
                'expires_in' => auth()->guard('api')->factory()->getTTL(),
            ];
            return ApiResponse::success($data, 'successful', 200, $metadata ?? null);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 'There was an error, Google sign-in failed.', 500);
        }
    }
}
