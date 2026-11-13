<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Reservas (Decisiones_Reservations, sección 6). */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('reservations', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique(['branch_id' => 1, 'confirmation_code' => 1]);
            $c->index(['branch_id' => 1, 'status' => 1, 'arrival_registered_at' => 1]);
            $c->index(['branch_id' => 1, 'status' => 1, 'start_at' => 1]);
            $c->index('customer_id');
            // La retención busca las que ya cumplieron 12 meses y aún tienen datos.
            $c->index(['personal_data_removed_at' => 1, 'end_at' => 1]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('reservations');
    }
};
