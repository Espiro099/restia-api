<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Recepciones y conteos físicos de Inventory (Decisiones_Inventory, sección 7). Guía, sección 8. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->create('inventory_receipts', function (Blueprint $c) {
            $c->unique('uuid');
            $c->index(['branch_id' => 1, 'received_at' => -1]);
        });

        $schema->create('inventory_counts', function (Blueprint $c) {
            $c->unique('uuid');
            // I-7: un solo conteo abierto por sucursal; dos aperturas simultáneas chocan aquí.
            $c->unique(['branch_id' => 1], 'un_conteo_abierto_por_sucursal', options: [
                'partialFilterExpression' => ['status' => 'OPEN'],
            ]);
            $c->index(['branch_id' => 1, 'status' => 1, 'created_at' => -1]);
        });
    }

    public function down(): void
    {
        foreach (['inventory_receipts', 'inventory_counts'] as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
