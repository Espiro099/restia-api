<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\FloorController;
use App\Http\Controllers\MobileAuthController;
use Illuminate\Support\Facades\Route;

// Todas las rutas quedan bajo /api/v1, como indica `servers` en openapi/restia-api.yaml.
// Cada módulo agrega aquí sus rutas. Fase 3, Identidad: flujo Android de D-36.
// Fase 4, servicio en piso: lo que usa la app Android para atender una mesa.

Route::prefix('auth/mobile')->controller(MobileAuthController::class)->group(function () {
    Route::post('enroll', 'enroll');
    Route::post('login', 'login');
    Route::post('refresh', 'refresh');
});

Route::middleware('auth.session')->group(function () {
    Route::controller(AccountController::class)->prefix('auth')->group(function () {
        Route::post('logout', 'logout');
        Route::get('me', 'me');
        Route::get('sessions', 'sessions');
        Route::post('sessions/{sessionId}/revoke', 'revoke');
    });

    Route::controller(DeviceController::class)->prefix('branches/{branchId}/devices')->group(function () {
        Route::post('enrollment-codes', 'enrollmentCode')->middleware('branch:devices.enroll');
        Route::get('/', 'index')->middleware('branch:devices.read');
        Route::post('{deviceId}/block', 'block')->middleware('branch:devices.manage');
        Route::post('{deviceId}/retire', 'retire')->middleware('branch:devices.manage');
    });

    Route::controller(FloorController::class)->prefix('branches/{branchId}')->group(function () {
        Route::get('tables', 'tables')->middleware('branch:tables.read');
        Route::get('tables/{tableId}', 'table')->middleware('branch:tables.read');
        Route::get('table-sessions', 'sessions')->middleware('branch:table_sessions.read');
        Route::post('table-sessions', 'openSession')->middleware('branch:table_sessions.open');
        Route::get('table-sessions/{sessionId}', 'session')->middleware('branch:table_sessions.read');
        Route::get('menu', 'menu')->middleware('branch:menu.read');
        Route::get('orders', 'orders')->middleware('branch:orders.read');
        Route::post('orders', 'createOrder')->middleware('branch:orders.create');
        Route::get('orders/{orderId}', 'order')->middleware('branch:orders.read');
        // El permiso depende de la acción (O-10); VOID admite un token de aprobación (D-37).
        Route::post('order-units/transitions', 'transitions')->middleware('branch');
        // Cualquier miembro puede pedir la autorización; el aprobador es quien necesita el permiso.
        Route::post('approvals', 'approve')->middleware('branch');
    });
});
