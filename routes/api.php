<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\MobileAuthController;
use Illuminate\Support\Facades\Route;

// Todas las rutas quedan bajo /api/v1, como indica `servers` en openapi/restia-api.yaml.
// Cada módulo agrega aquí sus rutas. Fase 3, Identidad: flujo Android de D-36.

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
});
