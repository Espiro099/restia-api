<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\FloorController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\PrintingController;
use App\Http\Controllers\PrintJobController;
use App\Http\Controllers\ServiceIdentityController;
use Illuminate\Support\Facades\Route;

// Todas las rutas quedan bajo /api/v1, como indica `servers` en openapi/restia-api.yaml.
// Cada módulo agrega aquí sus rutas. Fase 3, Identidad: flujo Android de D-36.
// Fase 4, servicio en piso: lo que usa la app Android para atender una mesa; e impresión.

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

    // Catálogo de Inventory: ingredientes, recetas y movimientos. START consume desde las transiciones.
    Route::controller(InventoryController::class)->prefix('branches/{branchId}')->group(function () {
        Route::get('ingredients', 'ingredients')->middleware('branch:inventory.read');
        Route::post('ingredients', 'createIngredient')->middleware('branch:inventory.manage');
        Route::get('ingredients/{ingredientId}', 'ingredient')->middleware('branch:inventory.read');
        Route::patch('ingredients/{ingredientId}', 'updateIngredient')->middleware('branch:inventory.manage');
        Route::post('ingredients/{ingredientId}/status', 'ingredientStatus')->middleware('branch:inventory.manage');
        // KITCHEN bloquea; desbloquear queda para ADMIN y MANAGER.
        Route::post('ingredients/{ingredientId}/food-safety', 'foodSafety')->middleware('branch:inventory.food_safety');

        Route::get('products/{productId}/recipes', 'recipes')->middleware('branch:recipes.read');
        Route::post('products/{productId}/recipes', 'publishRecipe')->middleware('branch:recipes.manage');
        Route::get('products/{productId}/recipes/{recipeVersion}', 'recipe')->middleware('branch:recipes.read');

        Route::get('inventory-movements', 'movements')->middleware('branch:inventory.read');
        // Recepciones y conteos llegan en la fase 5; un ajuste sirve para cargar el stock inicial.
        Route::post('inventory-movements', 'createMovement')->middleware('branch');
    });

    // Identidades de servicio (D-36, sección 11): recurso global, visible por sucursal administrada.
    Route::controller(ServiceIdentityController::class)->prefix('service-identities')->middleware('anybranch:service_identities.manage')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'create');
        Route::patch('{serviceIdentityId}', 'update');
        Route::post('{serviceIdentityId}/rotate', 'rotate');
        Route::post('{serviceIdentityId}/revoke', 'revoke');
    });

    // Configuración de impresión (Decisiones_Printing).
    Route::controller(PrintingController::class)->prefix('branches/{branchId}')->group(function () {
        Route::get('printers', 'printers')->middleware('branch:printing.read');
        Route::post('printers', 'createPrinter')->middleware('branch:printing.manage');
        Route::get('printers/{printerId}', 'printer')->middleware('branch:printing.read');
        Route::patch('printers/{printerId}', 'updatePrinter')->middleware('branch:printing.manage');
        Route::post('printers/{printerId}/status', 'printerStatus')->middleware('branch:printing.manage');

        Route::get('print-stations', 'printStations')->middleware('branch:printing.read');
        Route::post('print-stations', 'createPrintStation')->middleware('branch:printing.manage');
        Route::get('print-stations/{printStationId}', 'printStation')->middleware('branch:printing.read');
        Route::patch('print-stations/{printStationId}', 'updatePrintStation')->middleware('branch:printing.manage');
        Route::post('print-stations/{printStationId}/status', 'printStationStatus')->middleware('branch:printing.manage');

        Route::get('print-agents', 'printAgents')->middleware('branch:printing.manage');
        Route::post('print-agents', 'createPrintAgent')->middleware('branch:printing.manage');
        Route::get('print-agents/{printAgentId}', 'printAgent')->middleware('branch:printing.manage');
        Route::post('print-agents/{printAgentId}/status', 'printAgentStatus')->middleware('branch:printing.manage');
    });

    // Trabajos de impresión: los ven todos; los automáticos salen de outbox (restia:printing).
    Route::controller(PrintJobController::class)->prefix('branches/{branchId}/print-jobs')->group(function () {
        Route::get('/', 'index')->middleware('branch:printing.read');
        Route::post('/', 'create')->middleware('branch:printing.request');
        Route::get('{printJobId}', 'show')->middleware('branch:printing.read');
        Route::get('{printJobId}/attempts', 'attempts')->middleware('branch:printing.read');
        Route::post('{printJobId}/retry', 'retry')->middleware('branch:printing.resolve');
        Route::post('{printJobId}/resolve', 'resolve')->middleware('branch:printing.resolve');
        Route::post('{printJobId}/cancel', 'cancel')->middleware('branch:printing.resolve');
        Route::post('{printJobId}/reprint', 'reprint')->middleware('branch:printing.reprint');
    });
});

// Service Bearer Token (D-36, sección 11): ni la app ni el panel entran aquí, y el agente no entra allá.
Route::get('service-identities/me', [ServiceIdentityController::class, 'me'])->middleware('auth.service');
Route::controller(PrintingController::class)->prefix('print-agent')->middleware('auth.service:PRINTING_AGENT')->group(function () {
    Route::get('config', 'agentConfig');
    Route::post('heartbeat', 'agentHeartbeat');
});
Route::controller(PrintJobController::class)->prefix('print-agent')->middleware('auth.service:PRINTING_AGENT')->group(function () {
    Route::post('jobs/claim', 'claim');
    Route::post('attempts/{attemptId}/start', 'start');
    Route::post('attempts/{attemptId}/result', 'result');
});
