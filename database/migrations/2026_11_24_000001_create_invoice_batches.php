<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Lotes de factura global (Decisiones_Fiscal, F-6 y F-7). */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('invoice_batches', function (Blueprint $c) {
            $c->unique('uuid');
            $c->index(['branch_id' => 1, 'created_at' => -1]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('invoice_batches');
    }
};
