<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = $request->validated('email_or_mobile');
        $password = $request->validated('password');

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('mobile', $identifier)
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        $credentials = [
            'password' => $password,
            'is_active' => true,
        ];

        if ($user->email && $identifier === $user->email) {
            $credentials['email'] = $identifier;
        } else {
            $credentials['mobile'] = $identifier;
        }
        if (! Auth::attempt($credentials, false)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        $request->session()->regenerate();

        return response()->json([
            'success' => true,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user->loadMissing('team:id,team_name');

        return response()->json([
            'id' => $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'team' => $user->team ? [
                'id' => $user->team->id,
                'team_name' => $user->team->team_name,
            ] : null,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'notification_settings' => null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
        ]);
    }
}
