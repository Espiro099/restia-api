<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\FloorController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PrintingController;
use App\Http\Controllers\PrintJobController;
use App\Http\Controllers\PublicQrController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ServiceIdentityController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Todas las rutas quedan bajo /api/v1, como indica `servers` en openapi/restia-api.yaml.
// Cada módulo agrega aquí sus rutas. Fase 3, Identidad: flujo Android de D-36.
// Fase 4, servicio en piso: lo que usa la app Android para atender una mesa; e impresión.

Route::prefix('auth/mobile')->controller(MobileAuthController::class)->group(function () {
    Route::post('enroll', 'enroll');
    Route::post('login', 'login');
    Route::post('refresh', 'refresh');
});

// Public QR (QR-1 a QR-12): el token del QR en X-QR-Token es la única credencial. Solo menú y presencia.
Route::controller(PublicQrController::class)->prefix('public')->middleware('qr')->group(function () {
    Route::get('context', 'context');
    Route::get('menu', 'menu');
    Route::post('presence', 'presence');
});

// Contraseñas sin sesión (U-2): recuperación por correo y enlace de un solo uso.
Route::post('auth/password/forgot', [UserController::class, 'forgotPassword']);
Route::post('auth/password/reset', [UserController::class, 'resetPassword']);

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

    // Caja (Decisiones_Cash). Las lecturas deciden los montos según cash.read o cash.read_open (CA-13).
    Route::controller(CashController::class)->prefix('branches/{branchId}')->group(function () {
        Route::get('cash-registers', 'registers')->middleware('branch');
        Route::post('cash-registers', 'createRegister')->middleware('branch:cash.manage_registers');
        Route::get('cash-registers/{cashRegisterId}', 'register')->middleware('branch');
        Route::patch('cash-registers/{cashRegisterId}', 'updateRegister')->middleware('branch:cash.manage_registers');
        Route::post('cash-registers/{cashRegisterId}/status', 'registerStatus')->middleware('branch:cash.manage_registers');

        Route::get('cash-shifts', 'shifts')->middleware('branch');
        Route::post('cash-shifts', 'open')->middleware('branch:cash.open');
        Route::get('cash-shifts/{cashShiftId}', 'shift')->middleware('branch');
        Route::get('cash-shifts/{cashShiftId}/summary', 'summary')->middleware('branch:cash.read');
        Route::get('cash-shifts/{cashShiftId}/movements', 'movements')->middleware('branch:cash.read');
        Route::post('cash-shifts/{cashShiftId}/movements', 'createMovement')->middleware('branch:cash.move');
        Route::post('cash-shifts/{cashShiftId}/start-closing', 'startClosing')->middleware('branch:cash.close');
        Route::post('cash-shifts/{cashShiftId}/cancel-closing', 'cancelClosing')->middleware('branch:cash.close');
        Route::post('cash-shifts/{cashShiftId}/close', 'close')->middleware('branch:cash.close');
    });

    // Pagos y reembolsos (Decisiones_Payments).
    Route::controller(PaymentController::class)->prefix('branches/{branchId}')->group(function () {
        Route::get('payments', 'index')->middleware('branch:payments.read');
        Route::post('payments', 'create')->middleware('branch:payments.create');
        Route::get('payments/{paymentId}', 'show')->middleware('branch:payments.read');
        Route::post('payments/{paymentId}/result', 'result')->middleware('branch:payments.record_result');
        Route::post('payments/{paymentId}/reconcile', 'reconcile')->middleware('branch:payments.reconcile');
        Route::post('payments/{paymentId}/refunds', 'createRefund')->middleware('branch:refunds.create');
        Route::get('refunds', 'refunds')->middleware('branch:refunds.read');
        Route::get('refunds/{refundId}', 'refund')->middleware('branch:refunds.read');
        Route::post('refunds/{refundId}/result', 'refundResult')->middleware('branch:refunds.record_result');
        Route::post('refunds/{refundId}/reconcile', 'refundReconcile')->middleware('branch:refunds.reconcile');
    });

    // Cuentas (Decisiones_Checks). Reabrir y dar cortesías admiten un token de aprobación (C-9).
    Route::controller(CheckController::class)->prefix('branches/{branchId}/checks')->group(function () {
        Route::get('/', 'index')->middleware('branch:checks.read');
        Route::post('/', 'create')->middleware('branch:checks.create');
        Route::get('{checkId}', 'show')->middleware('branch:checks.read');
        Route::post('{checkId}/units/move', 'moveUnits')->middleware('branch:checks.move_units');
        Route::post('{checkId}/request', 'request')->middleware('branch:checks.request');
        Route::post('{checkId}/reopen', 'reopen')->middleware('branch:checks.read');
        Route::post('{checkId}/cancel', 'cancel')->middleware('branch:checks.cancel');
        Route::post('{checkId}/adjustments', 'adjust')->middleware('branch:checks.read');
        Route::post('{checkId}/adjustments/{adjustmentId}/revert', 'revert')->middleware('branch:checks.read');
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
        // El permiso depende del tipo: inventory.adjust o inventory.record_waste.
        Route::post('inventory-movements', 'createMovement')->middleware('branch');

        Route::get('inventory-receipts', 'receipts')->middleware('branch:inventory.read');
        Route::post('inventory-receipts', 'createReceipt')->middleware('branch:inventory.receive');
        Route::get('inventory-receipts/{receiptId}', 'receipt')->middleware('branch:inventory.read');

        Route::get('inventory-counts', 'counts')->middleware('branch:inventory.read');
        Route::post('inventory-counts', 'createCount')->middleware('branch:inventory.count');
        Route::get('inventory-counts/{countId}', 'count')->middleware('branch:inventory.read');
        Route::put('inventory-counts/{countId}/lines', 'countLines')->middleware('branch:inventory.count');
        Route::post('inventory-counts/{countId}/apply', 'applyCount')->middleware('branch:inventory.apply_count');
        Route::post('inventory-counts/{countId}/cancel', 'cancelCount')->middleware('branch:inventory.apply_count');
    });

    // Reservas del personal (R-1 a R-12). Las rutas fijas van antes de {reservationId}.
    Route::controller(ReservationController::class)->prefix('branches/{branchId}/reservations')->group(function () {
        Route::get('availability', 'availability')->middleware('branch:reservations.availability');
        Route::post('lookup', 'lookup')->middleware('branch:reservations.lookup');
        Route::get('waiting-queue', 'queue')->middleware('branch:reservations.read');
        Route::get('/', 'index')->middleware('branch:reservations.read');
        Route::post('/', 'create')->middleware('branch:reservations.create');
        Route::get('{reservationId}', 'show')->middleware('branch:reservations.read');
        Route::patch('{reservationId}', 'update')->middleware('branch:reservations.update');
        Route::post('{reservationId}/confirm', 'confirm')->middleware('branch:reservations.confirm');
        Route::post('{reservationId}/arrive', 'arrive')->middleware('branch:reservations.arrive');
        Route::post('{reservationId}/check-in', 'checkIn')->middleware('branch:reservations.check_in');
        Route::post('{reservationId}/cancel', 'cancel')->middleware('branch:reservations.cancel');
        Route::post('{reservationId}/no-show', 'noShow')->middleware('branch:reservations.mark_no_show');
    });

    // Empleados por sucursal (U-1, U-4, U-5): MANAGER solo WAITER y KITCHEN; nadie se cambia a sí mismo.
    Route::controller(MemberController::class)->prefix('branches/{branchId}/members')->group(function () {
        Route::get('/', 'index')->middleware('branch:members.read');
        Route::post('/', 'create')->middleware('branch:members.manage');
        Route::get('{userId}', 'show')->middleware('branch:members.read');
        Route::patch('{userId}', 'update')->middleware('branch:members.manage');
        Route::post('{userId}/revoke', 'revoke')->middleware('branch:members.manage');
    });

    // Usuario global: el actor debe ser ADMIN en todas las sucursales del usuario (A-10).
    Route::controller(UserController::class)->prefix('users/{userId}')->group(function () {
        Route::post('password-setup-link', 'setupLink')->middleware('anybranch:users.manage');
        Route::post('deactivate', 'deactivate')->middleware('anybranch:users.manage');
        Route::post('activate', 'activate')->middleware('anybranch:users.manage');
        Route::post('sessions/revoke', 'revokeSessions')->middleware('anybranch:users.sessions.revoke');
    });
    Route::post('auth/password/change', [UserController::class, 'changePassword']);

    // Clientes (CU-1 a CU-4): cada sucursal ve solo los suyos; la búsqueda va por POST (CU-3).
    Route::controller(CustomerController::class)->prefix('branches/{branchId}/customers')->group(function () {
        Route::post('/', 'create')->middleware('branch:customers.create');
        Route::post('search', 'search')->middleware('branch:customers.read');
        Route::get('{customerId}', 'show')->middleware('branch:customers.read');
        Route::patch('{customerId}', 'update')->middleware('branch:customers.update');
        Route::post('{customerId}/anonymize', 'anonymize')->middleware('branch:customers.anonymize');
    });

    // Token QR de una mesa, para imprimirlo o rotarlo (QR-4, QR-12).
    Route::get('branches/{branchId}/tables/{tableId}/qr', [PublicQrController::class, 'show'])->middleware('branch:tables.qr_read');
    Route::post('branches/{branchId}/tables/{tableId}/qr/rotate', [PublicQrController::class, 'rotate'])->middleware('branch:tables.qr_rotate');

    // Reportes (RP-1 a RP-3): proyecciones por día; la reconstrucción corre con restia:reports.
    Route::controller(ReportController::class)->prefix('branches/{branchId}/reports')->group(function () {
        Route::get('daily-sales', 'dailySales')->middleware('branch:reports.read');
        Route::get('hourly-occupancy', 'hourlyOccupancy')->middleware('branch:reports.read');
        Route::post('rebuilds', 'rebuild')->middleware('branch:reports.rebuild');
        Route::get('rebuilds/{rebuildId}', 'rebuildStatus')->middleware('branch:reports.rebuild');
    });

    // Auditoría (AU-1): solo lectura. La global incluye lo que no es de ninguna sucursal.
    Route::get('branches/{branchId}/audit-log', [AuditController::class, 'branch'])->middleware('branch:audit.read');
    Route::get('audit-log', [AuditController::class, 'global'])->middleware('anybranch:audit.read_global');

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
