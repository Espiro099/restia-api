<?php

// Duraciones y límites de D-36 (secciones 5.2 y 8). Cambiarlos requiere actualizar esa decisión.
return [
    'auth' => [
        'access_token_seconds' => 15 * 60,
        // Sección 30: rechazar contraseñas filtradas consultando Have I Been Pwned por k-anonimato.
        // Se apaga en pruebas para no depender de la red.
        'check_breached_passwords' => (bool) env('RESTIA_CHECK_BREACHED_PASSWORDS', true),
        'refresh_idle_seconds' => 7 * 24 * 3600,
        'session_max_seconds' => 30 * 24 * 3600,
        'rotation_grace_seconds' => 60,
        'enrollment_code_seconds' => 15 * 60,
        'android_sessions_per_user' => 2,
        'session_retention_days' => 180,

        // Clave HMAC de los refresh tokens. Por omisión se deriva de APP_KEY; rotarla cierra
        // todas las sesiones Android, porque ningún refresh emitido antes vuelve a ser válido.
        'refresh_key' => env('RESTIA_REFRESH_KEY'),

        // Intentos por minuto (D-36, sección 8).
        'limits' => [
            'login_per_email_ip' => 5,
            'login_per_ip' => 20,
            'refresh_per_session' => 30,
            'enroll_per_ip' => 10,
            'enrollment_codes_per_user' => 10,
        ],
    ],

    // Servicio en piso (fase 4).
    'floor' => [
        // Corte de business_date si la sucursal no define el suyo (B-6).
        'business_day_cutoff' => '04:00',
        'idempotency_hours' => 72,
        // D-37: el token de un gerente dura 2 minutos; 5 intentos por minuto por aprobador e IP.
        'approval_seconds' => 120,
        'approvals_per_approver_ip' => 5,
    ],

    // Identidades de servicio (D-36, sección 11) e impresión (Decisiones_Printing).
    'service' => [
        'token_days' => 90,
        // Al rotar, el token anterior sigue valiendo este tiempo, para desplegar sin cortes.
        'rotation_grace_days' => 7,
    ],
    // Pagos (Decisiones_Payments): un PENDING sin resultado pasa a UNKNOWN tras este tiempo (P-4).
    'payments' => [
        'pending_minutes' => 15,
    ],
    'printing' => [
        'poll_interval_seconds' => 3,
        // PR-5: plazo de un reclamo; `start` lo renueva.
        'lease_seconds' => 60,
        // PR-6: reintentos automáticos solo si el agente confirma que no envió nada.
        'max_attempts' => 3,
        'retry_base_seconds' => 5,
        // PR-9: una comanda sin imprimir tras este tiempo pasa a FAILED. Cada sucursal puede
        // definir el suyo en `print_ticket_expiry_minutes`.
        'ticket_expiry_minutes' => 15,
        // Sin latido por este tiempo se publica print_agent.offline.
        'agent_offline_seconds' => 120,
    ],
    // Panel Web: los enlaces de contraseña llevan a `<url>/contrasena#<token>` (U-2). Mientras no exista,
    // la sirve esta misma API, así que por omisión apunta a APP_URL.
    'web' => [
        'url' => env('RESTIA_WEB_URL', env('APP_URL', 'http://127.0.0.1:8000')),
    ],
    // Public QR (Decisiones_Public_QR). El QR codifica `<url>/m#<token>` (QR-5).
    'public' => [
        'url' => env('RESTIA_PUBLIC_URL', 'http://127.0.0.1:5173'),
        // QR-4: el token anterior sigue sirviendo este tiempo después de rotar.
        'previous_token_days' => 7,
        // QR-8: una señal de presencia marca la mesa como posible ocupación por este tiempo.
        'presence_minutes' => 10,
        // QR-9, valores iniciales por minuto; falta medirlos con el wifi del restaurante (tarea 37).
        'limits' => [
            'reads_per_ip' => 60,
            'presence_per_token' => 6,
            'invalid_per_ip' => 20,
        ],
    ],

    // Fiscal (Decisiones_Fiscal). F-1: un conector por PAC; mientras no se elige proveedor, el simulado.
    'fiscal' => [
        'pac' => env('RESTIA_PAC', 'simulated'),
        // F-14: XML y PDF timbrados. En producción, un disco de almacenamiento de objetos.
        'disk' => env('RESTIA_FISCAL_DISK', 'local'),
        // Serie por tipo de comprobante; el folio es un contador por emisor y serie.
        'series' => ['ingreso' => 'A', 'egreso' => 'NC'],
    ],
];
