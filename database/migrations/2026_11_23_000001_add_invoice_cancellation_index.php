<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** El proceso de cancelación busca por estado y próxima revisión. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->table('invoices', function (Blueprint $c) {
            $c->index(['status' => 1, 'cancel_next_at' => 1], 'invoices_status_cancel_next_at');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->table('invoices', function (Blueprint $c) {
            $c->dropIndex('invoices_status_cancel_next_at');
        });
    }
};
