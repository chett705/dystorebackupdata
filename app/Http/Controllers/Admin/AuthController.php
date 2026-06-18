<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // កែប្រែត្រង់នេះ៖ លុប ->where('is_admin', true) ចោលដើម្បីកុំឱ្យឆែក Role
        $user = User::query()
            ->where('email', $validated['email'])
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.', // ប្ដូរពាក្យកុំឱ្យមានជាប់ពាក្យ admin នាំច្រឡំ
            ], 422);
        }

        $token = Str::random(64);

        $user->forceFill([
            'admin_api_token_hash' => hash('sha256', $token),
        ])->save();

        return response()->json([
            'message' => 'Login successful.',
            'token_type' => 'Bearer',
            'access_token' => $token,
            // កែប្រែត្រង់នេះ៖ លុប 'is_admin' ចេញពីបញ្ជីទិន្នន័យដែលត្រូវផ្ញើទៅ React
            'admin' => $user->only(['id', 'name', 'email']), 
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $user->forceFill([
                'admin_api_token_hash' => null,
            ])->save();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}