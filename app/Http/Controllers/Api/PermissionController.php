<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePermissionRequest;
use App\Http\Requests\Api\UpdatePermissionRequest;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $permissions = Permission::query()
            ->orderBy('name')
            ->get()
            ->map(function (Permission $permission): array {
                [$module, $action] = $this->splitPermissionName($permission->name);

                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'module' => $permission->module ?? $module,
                    'action' => $permission->action ?? $action,
                    'is_active' => (bool) ($permission->is_active ?? true),
                ];
            })
            ->values();

        $grouped = $permissions->groupBy('module')->map(fn ($items) => $items->values());

        return response()->json([
            'data' => $permissions,
            'grouped' => $grouped,
        ]);
    }

    public function store(StorePermissionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $name = $validated['name'];
        $module = $validated['module'] ?? $this->splitPermissionName($name)[0];
        $action = $validated['action'] ?? $this->splitPermissionName($name)[1];

        $permission = Permission::query()->create([
            'name' => $name,
            'guard_name' => 'web',
            'module' => $module,
            'action' => $action,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Permission created successfully.',
            'data' => $permission,
        ], 201);
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        $validated = $request->validated();

        $name = $validated['name'] ?? $permission->name;
        [$fallbackModule, $fallbackAction] = $this->splitPermissionName($name);

        $permission->update([
            'name' => $name,
            'module' => $validated['module'] ?? ($permission->module ?? $fallbackModule),
            'action' => $validated['action'] ?? ($permission->action ?? $fallbackAction),
            'is_active' => $validated['is_active'] ?? ($permission->is_active ?? true),
        ]);

        return response()->json([
            'message' => 'Permission updated successfully.',
            'data' => $permission,
        ]);
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $permission->update(['is_active' => false]);

        return response()->json([
            'message' => 'Permission disabled successfully.',
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPermissionName(string $name): array
    {
        $parts = explode('.', $name, 2);

        return [
            $parts[0] ?? 'general',
            $parts[1] ?? 'view',
        ];
    }
}
