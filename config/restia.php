<?php

// Duraciones y límites de D-36 (secciones 5.2 y 8). Cambiarlos requiere actualizar esa decisión.
return [
    'auth' => [
        'access_token_seconds' => 15 * 60,
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
    'printing' => [
        'poll_interval_seconds' => 3,
    ],
];
