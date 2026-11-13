<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Proyecciones de reportes y sus reconstrucciones (Decisiones_Modulos_Restantes, sección 9). */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->create('daily_sales', function (Blueprint $c) {
            $c->unique(['branch_id' => 1, 'business_date' => 1]);
        });

        $schema->create('hourly_occupancy', function (Blueprint $c) {
            $c->unique(['branch_id' => 1, 'business_date' => 1, 'position' => 1]);
        });

        $schema->create('report_rebuilds', function (Blueprint $c) {
            $c->unique('uuid');
            // Una sola reconstrucción activa por sucursal; dos pedidas a la vez chocan aquí.
            $c->unique(['branch_id' => 1], 'una_reconstruccion_activa', options: ['partialFilterExpression' => ['active' => true]]);
            $c->index(['status' => 1, 'created_at' => 1]);
        });

        // El consumidor de reportes busca por tipo lo que todavía no atendió.
        Schema::connection('mongodb')->table('outbox_events', function (Blueprint $c) {
            $c->index(['type' => 1, 'created_at' => 1]);
        });
    }

    public function down(): void
    {
        foreach (['daily_sales', 'hourly_occupancy', 'report_rebuilds'] as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
        Schema::connection('mongodb')->table('outbox_events', function (Blueprint $c) {
            $c->dropIndex(['type' => 1, 'created_at' => 1]);
        });
    }
};
