<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Trabajos de impresión y sus intentos (Decisiones_Printing, sección 7). Guía, sección 8. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->create('print_jobs', function (Blueprint $c) {
            $c->unique('uuid');
            // Clave derivada de los trabajos automáticos: un evento repetido no duplica la comanda.
            // Los pedidos a mano no la llevan; su Idempotency-Key vive en idempotency_records.
            $c->unique('idempotency_key', 'idempotency_key_unica', options: [
                'partialFilterExpression' => ['idempotency_key' => ['$type' => 'string']],
            ]);
            // Para reclamar: lo más urgente y lo más antiguo primero.
            $c->index(['branch_id' => 1, 'printer_id' => 1, 'status' => 1, 'priority_rank' => 1, 'created_at' => 1]);
            $c->index(['branch_id' => 1, 'status' => 1, 'created_at' => 1]);
            $c->index(['source_type' => 1, 'source_id' => 1]);
            $c->index('claimed_agent_id');
            // Para vencer plazos.
            $c->index(['status' => 1, 'lease_until' => 1]);
        });

        $schema->create('print_job_attempts', function (Blueprint $c) {
            $c->unique('uuid');
            $c->index(['job_id' => 1, 'claimed_at' => 1]);
        });
    }

    public function down(): void
    {
        foreach (['print_jobs', 'print_job_attempts'] as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
