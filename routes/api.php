<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CatalogController;
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
        Route::patch('table-sessions/{sessionId}', 'updateSession')->middleware('branch:table_sessions.update');
        Route::post('table-sessions/{sessionId}/tables', 'addTables')->middleware('branch:table_sessions.update');
        Route::post('table-sessions/{sessionId}/tables/{tableId}/release', 'releaseTable')->middleware('branch:table_sessions.update');
        Route::post('table-sessions/{sessionId}/tables/{tableId}/cleaning-confirmation', 'confirmCleaning')->middleware('branch:tables.clean');
        Route::post('table-sessions/{sessionId}/close', 'closeSession')->middleware('branch:table_sessions.close');
        Route::post('table-sessions/{sessionId}/cancel', 'cancelSession')->middleware('branch:table_sessions.cancel');
        Route::get('menu', 'menu')->middleware('branch:menu.read');
        Route::get('orders', 'orders')->middleware('branch:orders.read');
        Route::post('orders', 'createOrder')->middleware('branch:orders.create');
        Route::get('orders/{orderId}', 'order')->middleware('branch:orders.read');
        Route::patch('orders/{orderId}/items/{itemId}', 'updateItem')->middleware('branch:orders.update');
        Route::get('order-units', 'units')->middleware('branch:orders.read');
        Route::post('order-units/{unitId}/station', 'reassign')->middleware('branch:order_units.reassign');
        // El permiso depende de la acción (O-10); VOID admite un token de aprobación (D-37).
        Route::post('order-units/transitions', 'transitions')->middleware('branch');
        // Cualquier miembro puede pedir la autorización; el aprobador es quien necesita el permiso.
        Route::post('approvals', 'approve')->middleware('branch');
    });

    // Catálogo y mesas: altas y cambios para el panel Web; Android lee y marca productos agotados.
    Route::controller(CatalogController::class)->prefix('branches/{branchId}')->group(function () {
        Route::post('tables', 'createTable')->middleware('branch:tables.manage');
        Route::patch('tables/{tableId}', 'updateTable')->middleware('branch:tables.manage');
        Route::post('tables/{tableId}/status', 'tableStatus')->middleware('branch:tables.manage');

        Route::get('stations', 'stations')->middleware('branch:menu.read');
        Route::post('stations', 'createStation')->middleware('branch:menu.manage');
        Route::get('stations/{stationId}', 'station')->middleware('branch:menu.read');
        Route::patch('stations/{stationId}', 'updateStation')->middleware('branch:menu.manage');
        Route::post('stations/{stationId}/status', 'stationStatus')->middleware('branch:menu.manage');

        Route::get('modifiers', 'modifiers')->middleware('branch:menu.read');
        Route::post('modifiers', 'createModifier')->middleware('branch:menu.manage');
        Route::get('modifiers/{modifierId}', 'modifier')->middleware('branch:menu.read');
        Route::patch('modifiers/{modifierId}', 'updateModifier')->middleware('branch:menu.manage');
        Route::post('modifiers/{modifierId}/status', 'modifierStatus')->middleware('branch:menu.manage');

        Route::get('products', 'products')->middleware('branch:menu.read');
        Route::post('products', 'createProduct')->middleware('branch:menu.manage');
        Route::get('products/{productId}', 'product')->middleware('branch:menu.read');
        Route::patch('products/{productId}', 'updateProduct')->middleware('branch:menu.manage');
        Route::post('products/{productId}/status', 'productStatus')->middleware('branch:menu.manage');
        Route::post('products/{productId}/availability', 'productAvailability')->middleware('branch:menu.set_availability');
        Route::put('products/{productId}/routes', 'productRoutes')->middleware('branch:menu.manage');
    });
});
