<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Pagos y reembolsos (Decisiones_Payments, sección 8; Decisiones_Cash, sección 6). Guía, sección 8. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->create('payments', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'idempotency_key' => 1]);
            $c->index(['branch_id' => 1, 'check_id' => 1, 'status' => 1]);
            $c->index(['branch_id' => 1, 'cash_shift_id' => 1, 'status' => 1]);
            // P-4: un PENDING de más de 15 minutos pasa a UNKNOWN.
            $c->index(['status' => 1, 'created_at' => 1]);
        });

        $schema->create('refunds', function (Blueprint $c) {
            $c->unique('uuid');
            // Un solo reembolso activo por pago: dos simultáneos chocan aquí (sección 5).
            $c->unique(['branch_id' => 1, 'payment_id' => 1], 'un_reembolso_activo_por_pago', options: [
                'partialFilterExpression' => ['active' => true],
            ]);
            $c->unique(['branch_id' => 1, 'idempotency_key' => 1]);
            $c->index(['branch_id' => 1, 'status' => 1, 'created_at' => 1]);
            $c->index(['branch_id' => 1, 'cash_shift_id' => 1, 'status' => 1]);
        });
    }

    public function down(): void
    {
        foreach (['payments', 'refunds'] as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
