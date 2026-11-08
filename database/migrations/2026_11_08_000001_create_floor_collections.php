<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Servicio en piso, primera parte: mesas y sesiones (T-1 a T-9), catálogo de Menu (M-1 a M-11),
 * comandas y unidades (O-1 a O-11), aprobaciones (D-37) e idempotencia (CON-1).
 * Índices de docs/Guia_Base_de_Datos_MongoDB.md, sección 8.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    private const COLLECTIONS = [
        'tables', 'table_sessions', 'table_assignments', 'stations', 'products', 'modifiers',
        'product_preparation_routes', 'orders', 'order_items', 'order_item_units', 'approval_grants',
        'idempotency_records',
    ];

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->create('tables', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'number' => 1]);
            $c->index(['branch_id' => 1, 'status' => 1]);
            // Parcial: el token QR llega con Public QR; las mesas sin él no chocan entre sí.
            $c->unique('qr_token', 'qr_token_unico', options: [
                'partialFilterExpression' => ['qr_token' => ['$type' => 'string']],
            ]);
        });

        $schema->create('table_sessions', function (Blueprint $c) {
            $c->unique('uuid');
            $c->index(['branch_id' => 1, 'status' => 1, 'created_at' => 1]);
            $c->index(['branch_id' => 1, 'business_date' => 1, 'status' => 1]);
        });

        $schema->create('table_assignments', function (Blueprint $c) {
            $c->unique('uuid');
            // Una sola asignación activa por mesa: dos aperturas simultáneas chocan aquí (T-9, sección 6).
            $c->unique(['branch_id' => 1, 'table_id' => 1], 'una_asignacion_activa_por_mesa', options: [
                'partialFilterExpression' => ['active' => true],
            ]);
            $c->index(['branch_id' => 1, 'session_id' => 1, 'active' => 1]);
            $c->index(['branch_id' => 1, 'table_id' => 1, 'active' => 1]);
        });

        foreach (['stations', 'modifiers'] as $name) {
            $schema->create($name, function (Blueprint $c) {
                $c->unique('uuid');
                $c->unique(['branch_id' => 1, 'code' => 1]);
            });
        }

        $schema->create('products', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'code' => 1]);
            $c->index(['branch_id' => 1, 'status' => 1, 'category' => 1]);
        });

        $schema->create('product_preparation_routes', function (Blueprint $c) {
            $c->unique(['branch_id' => 1, 'product_id' => 1, 'station_id' => 1]);
        });

        $schema->create('orders', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'client_order_uuid' => 1]);
            $c->index(['branch_id' => 1, 'session_id' => 1, 'created_at' => 1]);
            $c->index(['branch_id' => 1, 'status' => 1, 'created_at' => 1]);
            $c->index(['branch_id' => 1, 'business_date' => 1, 'status' => 1]);
        });

        $schema->create('order_items', function (Blueprint $c) {
            $c->unique('uuid');
            $c->index(['branch_id' => 1, 'order_id' => 1]);
        });

        $schema->create('order_item_units', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'order_id' => 1, 'order_item_id' => 1, 'unit_number' => 1]);
            $c->index(['branch_id' => 1, 'order_item_id' => 1, 'status' => 1]);
            $c->index(['branch_id' => 1, 'status' => 1, 'station_id' => 1, 'created_at' => 1]);
        });

        $schema->create('approval_grants', function (Blueprint $c) {
            $c->unique('token_hash');
            $c->expire('expires_at', 0);
        });

        $schema->create('idempotency_records', function (Blueprint $c) {
            $c->unique(['branch_id' => 1, 'key' => 1]);
            $c->expire('expires_at', 0);
        });
    }

    public function down(): void
    {
        foreach (self::COLLECTIONS as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
