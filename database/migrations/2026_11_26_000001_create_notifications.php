<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Avisos por empleado (campana de la app). Se borran solos a los 30 días. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('notifications', function (Blueprint $c) {
            $c->unique('uuid');
            // Lista de la campana: avisos propios de la sucursal, los más recientes primero.
            $c->index(['user_id' => 1, 'branch_id' => 1, 'updated_at' => -1]);
            $c->index(['user_id' => 1, 'branch_id' => 1, 'read_at' => 1]);
            // Agrupa "orden lista" mientras no se lea.
            $c->index(['group_key' => 1, 'read_at' => 1]);
            $c->index(['expires_at' => 1], options: ['expireAfterSeconds' => 0]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('notifications');
    }
};
