<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'auth.user.view',
            'auth.user.create',
            'auth.user.edit',
            'auth.role.view',
            'auth.role.create',
            'auth.role.edit',
            'auth.role.delete',
            'auth.permission.view',
            'auth.permission.create',
            'auth.permission.edit',
            'auth.permission.delete',
            'customer.view',
            'customer.create',
            'customer.edit',
            'customer.approve_change',
            'masters.manage',
            'query.view',
            'query.create',
            'query.assign',
            'query.change_status',
        ];

        foreach ($permissions as $permissionName) {
            [$module, $action] = array_pad(explode('.', $permissionName, 2), 2, 'view');

            Permission::query()->firstOrCreate(
                [
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ],
                [
                    'module' => $module,
                    'action' => $action,
                    'is_active' => true,
                ]
            );
        }

        $superAdmin = Role::query()->firstOrCreate(
            ['name' => 'Super Admin', 'guard_name' => 'web'],
            ['is_active' => true]
        );

        $admin = Role::query()->firstOrCreate(
            ['name' => 'Admin', 'guard_name' => 'web'],
            ['is_active' => true]
        );

        $superAdmin->syncPermissions(Permission::query()->where('is_active', true)->get());
        $admin->syncPermissions([
            'auth.user.view',
            'auth.user.create',
            'auth.user.edit',
            'auth.role.view',
            'auth.role.create',
            'auth.role.edit',
            'auth.permission.view',
            'auth.permission.create',
            'auth.permission.edit',
            'customer.view',
            'customer.create',
            'customer.edit',
            'customer.approve_change',
            'masters.manage',
            'query.view',
            'query.create',
            'query.assign',
            'query.change_status',
        ]);

        $user = User::query()
            ->where('email', 'admin@obokash.com')
            ->orWhere('mobile', '01700000000')
            ->first();

        if (! $user) {
            $user = User::query()->create([
                'email' => 'admin@obokash.com',
                'full_name' => 'System Super Admin',
                'mobile' => '01700000000',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]);
        } else {
            $user->fill([
                'email' => $user->email ?: 'admin@obokash.com',
                'full_name' => 'System Super Admin',
                'is_active' => true,
            ]);

            if (! $user->mobile) {
                $user->mobile = '01700000000';
            }

            $user->save();
        }

        $user->assignRole($superAdmin);
    }
}
