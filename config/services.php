<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // PAC SW Sapien (RESTIA_PAC=sw). Pruebas: https://services.test.sw.com.mx; producción: https://services.sw.com.mx.
    // SW_TOKEN es el token "infinito" del portal; si falta, se autentica con SW_USER y SW_PASSWORD.
    // Ninguno se versiona: van solo en .env.
    'sw' => [
        'url' => env('SW_URL', 'https://services.test.sw.com.mx'),
        'token' => env('SW_TOKEN'),
        'user' => env('SW_USER'),
        'password' => env('SW_PASSWORD'),
        'timeout' => (int) env('SW_TIMEOUT', 30),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
