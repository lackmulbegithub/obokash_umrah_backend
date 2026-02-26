<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerChangeRequestController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\QueryController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Session-backed login for Sanctum SPA auth
Route::middleware(['web', 'guest'])->post('/login', [AuthController::class, 'login']);

Route::middleware(['web', 'auth:sanctum'])->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/bootstrap/options', [BootstrapController::class, 'options']);

    Route::middleware('permission:auth.user.view')->group(function (): void {
        Route::get('/users', [UserController::class, 'index']);
    });

    Route::middleware('permission:auth.user.create')->group(function (): void {
        Route::post('/users', [UserController::class, 'store']);
    });

    Route::middleware('permission:auth.user.edit')->group(function (): void {
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/activate', [UserController::class, 'activate']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
    });

    Route::middleware('permission:auth.role.view')->group(function (): void {
        Route::get('/roles', [RoleController::class, 'index']);
    });

    Route::middleware('permission:auth.role.create')->group(function (): void {
        Route::post('/roles', [RoleController::class, 'store']);
    });

    Route::middleware('permission:auth.role.edit')->group(function (): void {
        Route::patch('/roles/{role}', [RoleController::class, 'update']);
        Route::post('/roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
    });

    Route::middleware('permission:auth.role.delete')->group(function (): void {
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
    });

    Route::middleware('permission:auth.permission.view')->group(function (): void {
        Route::get('/permissions', [PermissionController::class, 'index']);
    });

    Route::middleware('permission:auth.permission.create')->group(function (): void {
        Route::post('/permissions', [PermissionController::class, 'store']);
    });

    Route::middleware('permission:auth.permission.edit')->group(function (): void {
        Route::patch('/permissions/{permission}', [PermissionController::class, 'update']);
    });

    Route::middleware('permission:auth.permission.delete')->group(function (): void {
        Route::delete('/permissions/{permission}', [PermissionController::class, 'destroy']);
    });

    Route::middleware('permission:customer.view')->group(function (): void {
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/search', [CustomerController::class, 'search']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    });

    Route::middleware('permission:customer.create')->group(function (): void {
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::post('/customers/minimal', [CustomerController::class, 'storeMinimal']);
    });

    Route::middleware('permission:customer.edit')->group(function (): void {
        Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
    });

    Route::middleware('permission:customer.approve_change')->group(function (): void {
        Route::get('/change-requests', [CustomerChangeRequestController::class, 'index']);
        Route::get('/change-requests/{changeRequest}', [CustomerChangeRequestController::class, 'show']);
        Route::post('/change-requests/{changeRequest}/approve', [CustomerChangeRequestController::class, 'approve']);
        Route::post('/change-requests/{changeRequest}/reject', [CustomerChangeRequestController::class, 'reject']);
    });

    Route::middleware('permission:masters.manage')->group(function (): void {
        Route::get('/masters/whatsapp-accounts', [MasterDataController::class, 'whatsappAccounts']);
        Route::post('/masters/whatsapp-accounts', [MasterDataController::class, 'storeWhatsappAccount']);
        Route::patch('/masters/whatsapp-accounts/{officialWhatsappNumber}', [MasterDataController::class, 'updateWhatsappAccount']);

        Route::get('/masters/emails', [MasterDataController::class, 'emails']);
        Route::post('/masters/emails', [MasterDataController::class, 'storeEmail']);
        Route::patch('/masters/emails/{officialEmail}', [MasterDataController::class, 'updateEmail']);

        Route::get('/masters/staff', [MasterDataController::class, 'staff']);
        Route::post('/masters/staff', [MasterDataController::class, 'storeStaff']);
        Route::patch('/masters/staff/{user}', [MasterDataController::class, 'updateStaff']);

        Route::get('/masters/districts', [MasterDataController::class, 'districts']);
        Route::post('/masters/districts', [MasterDataController::class, 'storeDistrict']);
        Route::patch('/masters/districts/{district}', [MasterDataController::class, 'updateDistrict']);

        Route::get('/masters/service-queues', [MasterDataController::class, 'serviceQueues']);
        Route::post('/masters/service-queues', [MasterDataController::class, 'upsertServiceQueue']);
        Route::get('/masters/service-queue-authorizations', [MasterDataController::class, 'serviceQueueAuthorizations']);
        Route::post('/masters/service-queue-authorizations', [MasterDataController::class, 'upsertServiceQueueAuthorization']);
    });

    Route::middleware('permission:team_authorization.manage')->group(function (): void {
        Route::get('/masters/team-role-assignments', [MasterDataController::class, 'teamRoleAssignments']);
        Route::post('/masters/team-role-assignments', [MasterDataController::class, 'upsertTeamRoleAssignment']);
    });

    Route::middleware('permission:query.view')->group(function (): void {
        Route::get('/queries/intake/search', [QueryController::class, 'intakeSearch']);
        Route::get('/queries', [QueryController::class, 'index']);
        Route::get('/queries/{query}', [QueryController::class, 'show']);
        Route::get('/query-items/self-queue/counters', [QueryController::class, 'selfQueueCounters']);
        Route::get('/query-items/self-queue', [QueryController::class, 'selfQueue']);
        Route::get('/query-items/team-queue/counters', [QueryController::class, 'teamQueueCounters']);
        Route::get('/query-items/team-queue', [QueryController::class, 'teamQueue']);
        Route::get('/query-items/notification-badges', [QueryController::class, 'queueNotificationBadges']);
    });

    Route::middleware('permission:query.create')->group(function (): void {
        Route::post('/queries', [QueryController::class, 'store']);
    });

    Route::middleware('permission:query.assign')->group(function (): void {
        Route::post('/query-items/{queryItem}/assign-to-me', [QueryController::class, 'assignToMe']);
        Route::post('/query-items/{queryItem}/assign-to-user', [QueryController::class, 'assignToUser']);
    });

    Route::middleware('permission:query.reassign')->group(function (): void {
        Route::post('/query-items/{queryItem}/reassign', [QueryController::class, 'reassignToUser']);
    });

    Route::middleware('permission:query.change_status')->group(function (): void {
        Route::patch('/queries/{query}/status', [QueryController::class, 'updateStatus']);
        Route::patch('/query-items/{queryItem}/status', [QueryController::class, 'updateItemStatus']);
    });
});
