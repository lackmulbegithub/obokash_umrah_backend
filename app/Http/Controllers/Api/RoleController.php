<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreRoleRequest;
use App\Http\Requests\Api\UpdateRoleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,name,module,action,is_active')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $roles]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $role = Role::query()->create([
            'name' => $validated['name'],
            'guard_name' => 'web',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Role created successfully.',
            'data' => $role,
        ], 201);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $validated = $request->validated();

        $role->update([
            'name' => $validated['name'] ?? $role->name,
            'is_active' => $validated['is_active'] ?? ($role->is_active ?? true),
        ]);

        return response()->json([
            'message' => 'Role updated successfully.',
            'data' => $role,
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $role->update(['is_active' => false]);

        return response()->json([
            'message' => 'Role disabled successfully.',
        ]);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string', 'exists:permissions,name'],
        ]);

        $permissionNames = Permission::query()
            ->whereIn('name', $validated['permissions'])
            ->where(function ($q): void {
                $q->whereNull('is_active')->orWhere('is_active', true);
            })
            ->pluck('name')
            ->all();

        $role->syncPermissions($permissionNames);

        return response()->json([
            'message' => 'Role permissions updated successfully.',
            'data' => $role->fresh('permissions'),
        ]);
    }
}
