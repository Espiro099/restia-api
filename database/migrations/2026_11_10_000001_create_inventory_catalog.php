<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Catálogo de Inventory y consumo en START (Decisiones_Inventory, sección 7). Guía, sección 8. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->create('ingredients', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'code' => 1]);
            $c->index(['branch_id' => 1, 'status' => 1]);
        });

        $schema->create('recipes', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'product_id' => 1, 'recipe_version' => 1]);
            // INGREDIENT_IN_USE: ¿alguna receta vigente usa el ingrediente?
            $c->index(['branch_id' => 1, 'status' => 1, 'items.ingredient_id' => 1]);
        });

        $schema->create('inventory_movements', function (Blueprint $c) {
            $c->unique('uuid');
            // I-2: clave derivada de unidad, ingrediente y versión de receta.
            $c->unique(['branch_id' => 1, 'idempotency_key' => 1]);
            $c->index(['branch_id' => 1, 'ingredient_id' => 1, 'created_at' => 1]);
            $c->index(['branch_id' => 1, 'source_type' => 1, 'source_id' => 1]);
            $c->index(['branch_id' => 1, 'type' => 1, 'created_at' => 1]);
            // Pendientes de proyectar en stock_actual (I-1).
            $c->index(['projected_at' => 1, 'created_at' => 1], 'pendientes_de_proyectar', options: [
                'partialFilterExpression' => ['projected_at' => null],
            ]);
            $c->index(['branch_id' => 1, 'created_at' => 1], 'autoaprobados', options: [
                'partialFilterExpression' => ['self_approved' => true],
            ]);
        });
    }

    public function down(): void
    {
        foreach (['ingredients', 'recipes', 'inventory_movements'] as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
