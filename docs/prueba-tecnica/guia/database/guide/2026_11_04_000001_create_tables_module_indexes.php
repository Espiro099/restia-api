<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('table_assignments', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'table_id' => 1], 'una_asignacion_activa_por_mesa', options: [
                'partialFilterExpression' => ['active' => true],
            ]);
            $c->index(['branch_id' => 1, 'session_id' => 1, 'active' => 1]);
        });

        Schema::connection('mongodb')->create('tables', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'number' => 1]);
            $c->unique('qr_token');
            // Parcial: las mesas que nunca rotaron no tienen este campo.
            $c->unique('previous_qr_token', 'qr_anterior', options: [
                'partialFilterExpression' => ['previous_qr_token' => ['$type' => 'string']],
            ]);
        });

        Schema::connection('mongodb')->create('idempotency_records', function (Blueprint $c) {
            $c->unique(['branch_id' => 1, 'key' => 1]);
            $c->expire('expires_at', 0);
        });
    }

    public function down(): void
    {
        foreach (['table_assignments', 'tables', 'idempotency_records'] as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
