<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

use Laravel\Socialite\Facades\Socialite;
use Tymon\JWTAuth\Facades\JWTAuth;

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
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            $user = User::updateOrCreate(
                ['google_id' => $googleUser->id],
                [
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'password' => null, // No password for social login
                ]
            );

            // Ensure a wallet is created if one doesn't exist
            if (!$user->wallet) {
                $user->wallet()->create(['balance' => 0]);
            }

            // Generate and return JWT
            $token = JWTAuth::fromUser($user);

            return response()->json([
                'token' => $token,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Google sign-in failed.'], 500);
        }
    }
}
