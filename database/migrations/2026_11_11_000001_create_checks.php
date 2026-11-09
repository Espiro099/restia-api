<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Cuentas, asignaciones de unidades y cortesías (Decisiones_Checks, sección 8). Guía, sección 8. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->create('checks', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'session_id' => 1, 'number' => 1]);
            $c->index(['branch_id' => 1, 'session_id' => 1, 'status' => 1]);
            $c->index(['branch_id' => 1, 'business_date' => 1, 'status' => 1]);
        });

        $schema->create('check_allocations', function (Blueprint $c) {
            // Una sola asignación activa por unidad: mover la misma unidad dos veces choca aquí (D-16).
            $c->unique(['branch_id' => 1, 'order_item_unit_id' => 1], 'una_asignacion_activa_por_unidad', options: [
                'partialFilterExpression' => ['active' => true],
            ]);
            $c->index(['branch_id' => 1, 'check_id' => 1, 'active' => 1]);
        });

        $schema->create('check_adjustments', function (Blueprint $c) {
            $c->unique('uuid');
            // Una cortesía activa por unidad (C-7).
            $c->unique(['branch_id' => 1, 'order_item_unit_id' => 1], 'una_cortesia_activa_por_unidad', options: [
                'partialFilterExpression' => ['active' => true],
            ]);
        });
    }

    public function down(): void
    {
        foreach (['checks', 'check_allocations', 'check_adjustments'] as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
