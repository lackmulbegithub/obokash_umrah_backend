<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreUserRequest;
use App\Http\Requests\Api\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('full_name')
            ->get();

        return response()->json(['data' => $users]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()->create([
            'full_name' => $validated['full_name'],
            'email' => $validated['email'] ?? null,
            'mobile' => $validated['mobile'] ?? null,
            'team_id' => $validated['team_id'] ?? null,
            'password' => $validated['password'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $user->syncRoles($validated['roles']);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $user->load('roles:id,name'),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        $user->fill([
            'full_name' => $validated['full_name'] ?? $user->full_name,
            'email' => $validated['email'] ?? $user->email,
            'mobile' => $validated['mobile'] ?? $user->mobile,
            'team_id' => $validated['team_id'] ?? $user->team_id,
            'is_active' => $validated['is_active'] ?? $user->is_active,
        ]);

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        if (isset($validated['roles'])) {
            $user->syncRoles($validated['roles']);
        }

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $user->load('roles:id,name'),
        ]);
    }

    public function activate(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        return response()->json([
            'message' => 'User activated successfully.',
        ]);
    }

    public function deactivate(User $user): JsonResponse
    {
        $user->update(['is_active' => false]);

        return response()->json([
            'message' => 'User deactivated successfully.',
        ]);
    }
}
