<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Identidad y acceso: docs/Guia_Base_de_Datos_MongoDB.md, sección 8, y D-36 (sección 4). */
return new class extends Migration
{
    protected $connection = 'mongodb';

    private const COLLECTIONS = [
        'branches', 'users', 'user_branch_memberships', 'devices', 'auth_sessions',
        'device_enrollment_codes', 'personal_access_tokens', 'audit_log', 'outbox_events',
    ];

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->create('branches', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique('code');
            $c->index('status');
        });

        $schema->create('users', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique('normalized_email');
            $c->index('status');
        });

        $schema->create('user_branch_memberships', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['user_id' => 1, 'branch_id' => 1]);
            $c->index(['branch_id' => 1, 'status' => 1]);
            $c->index(['user_id' => 1, 'status' => 1]);
        });

        $schema->create('devices', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique('device_uuid');
            $c->index(['branch_id' => 1, 'status' => 1, 'type' => 1]);
        });

        $schema->create('auth_sessions', function (Blueprint $c) {
            $c->unique('uuid');
            // Una sola sesión activa por dispositivo (A-3): el segundo login concurrente choca aquí.
            $c->unique('device_id', 'una_sesion_activa_por_dispositivo', options: [
                'partialFilterExpression' => ['status' => 'ACTIVE', 'client_type' => 'ANDROID'],
            ]);
            $c->index(['user_id' => 1, 'status' => 1, 'client_type' => 1]);
            $c->index(['status' => 1, 'absolute_expires_at' => 1]);
            $c->expire('purge_at', 0);
        });

        $schema->create('device_enrollment_codes', function (Blueprint $c) {
            $c->unique('code_hash');
            $c->expire('expires_at', 0);
        });

        $schema->create('personal_access_tokens', function (Blueprint $c) {
            $c->unique('token');
            $c->index(['tokenable_type' => 1, 'tokenable_id' => 1]);
            $c->index('auth_session_id');
            // Sanctum ya rechaza los vencidos; el TTL solo limpia. Los access tokens duran 15 minutos.
            $c->expire('expires_at', 3600);
        });

        $schema->create('audit_log', function (Blueprint $c) {
            $c->index(['branch_id' => 1, 'entity_type' => 1, 'entity_id' => 1, 'created_at' => 1]);
            $c->index(['branch_id' => 1, 'actor_id' => 1, 'created_at' => 1]);
            $c->index(['branch_id' => 1, 'created_at' => 1]);
        });

        $schema->create('outbox_events', function (Blueprint $c) {
            $c->index(['status' => 1, 'next_attempt_at' => 1]);
            $c->index(['branch_id' => 1, 'source_type' => 1, 'source_id' => 1]);
        });
    }

    public function down(): void
    {
        foreach (self::COLLECTIONS as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
