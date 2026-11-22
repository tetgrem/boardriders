<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crm65\Crm65Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

final class Crm65SessionController extends Controller
{
    public function login(Request $request, Crm65Staff $staff): JsonResponse
    {
        if (config('crm65.enabled') !== true) {
            return response()->json(['message' => 'CRM unavailable'], 503);
        }

        $input = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $email = mb_strtolower(trim($input['email']));
        $key = 'crm65:login:' . hash('sha256', $email . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many attempts'], 429);
        }

        $user = User::query()->where('email', $email)->first();
        $valid = $user !== null
            && Hash::check($input['password'], (string) $user->password)
            && $user->email_verified_at !== null
            && $staff->role($user) !== null;

        if (!$valid) {
            RateLimiter::hit($key, 300);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        RateLimiter::clear($key);

        $hours = (int) config('crm65.token_hours', 8);
        $expiresAt = now()->addHours($hours);
        $token = $user->createToken('crm65-dashboard', ['crm65:access'], $expiresAt);

        return response()->json([
            'access_token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    public function me(Request $request, Crm65Staff $staff): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            return response()->json($staff->identity($user))
                ->header('Cache-Control', 'no-store');
        } catch (\DomainException) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if (!$token instanceof PersonalAccessToken
            || !$token->can('crm65:access')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $token->delete();

        return response()->json(['logged_out' => true])
            ->header('Cache-Control', 'no-store');
    }
}
