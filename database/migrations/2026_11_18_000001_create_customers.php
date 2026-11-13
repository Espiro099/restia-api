<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Clientes y su perfil por sucursal (Decisiones_Modulos_Restantes, sección 9). */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->create('customers', function (Blueprint $c) {
            $c->unique('uuid');
            // Sin único: el mismo teléfono en otra sucursal es otra identidad (CU-2).
            $c->index('phone');
        });

        $schema->create('customer_branch_profiles', function (Blueprint $c) {
            $c->unique(['branch_id' => 1, 'customer_id' => 1]);
            $c->index('customer_id');
        });
    }

    public function down(): void
    {
        foreach (['customers', 'customer_branch_profiles'] as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
