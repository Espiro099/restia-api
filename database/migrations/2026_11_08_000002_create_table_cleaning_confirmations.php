<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Limpieza por mesa (T-2), validada con session_membership_version. Guía, sección 8. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('table_cleaning_confirmations', function (Blueprint $c) {
            $c->unique(['branch_id' => 1, 'session_id' => 1, 'table_id' => 1, 'session_membership_version' => 1]);
            $c->index(['branch_id' => 1, 'session_id' => 1, 'status' => 1]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('table_cleaning_confirmations');
    }
};
