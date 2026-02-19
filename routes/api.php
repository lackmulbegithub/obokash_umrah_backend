<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\CustomerController;
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
        Route::get('/customers/search', [CustomerController::class, 'search']);
    });

    Route::middleware('permission:customer.create')->group(function (): void {
        Route::post('/customers', [CustomerController::class, 'store']);
    });

    Route::middleware('permission:customer.edit')->group(function (): void {
        Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
    });

    Route::middleware('permission:query.view')->group(function (): void {
        Route::get('/queries', [QueryController::class, 'index']);
        Route::get('/queries/{query}', [QueryController::class, 'show']);
        Route::get('/query-items/team-queue', [QueryController::class, 'teamQueue']);
    });

    Route::middleware('permission:query.create')->group(function (): void {
        Route::post('/queries', [QueryController::class, 'store']);
    });

    Route::middleware('permission:query.assign')->group(function (): void {
        Route::post('/query-items/{queryItem}/assign-to-me', [QueryController::class, 'assignToMe']);
    });

    Route::middleware('permission:query.change_status')->group(function (): void {
        Route::patch('/queries/{query}/status', [QueryController::class, 'updateStatus']);
    });
});
