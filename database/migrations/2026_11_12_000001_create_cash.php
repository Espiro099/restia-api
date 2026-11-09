<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Cajas, turnos y movimientos manuales (Decisiones_Cash, sección 8). Guía, sección 8. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->create('cash_registers', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'code' => 1]);
        });

        $schema->create('cash_shifts', function (Blueprint $c) {
            $c->unique('uuid');
            // CA-12: un solo turno OPEN o CLOSING por caja; dos aperturas simultáneas chocan aquí.
            $c->unique(['branch_id' => 1, 'cash_register_id' => 1], 'un_turno_activo_por_caja', options: [
                'partialFilterExpression' => ['active' => true],
            ]);
            $c->unique(['branch_id' => 1, 'idempotency_key' => 1]);
            $c->index(['branch_id' => 1, 'status' => 1, 'opened_at' => 1]);
            $c->index(['branch_id' => 1, 'cash_register_id' => 1, 'closed_at' => 1]);
        });

        $schema->create('cash_movements', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'idempotency_key' => 1]);
            $c->index(['branch_id' => 1, 'cash_shift_id' => 1, 'created_at' => 1]);
        });
    }

    public function down(): void
    {
        foreach (['cash_registers', 'cash_shifts', 'cash_movements'] as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
