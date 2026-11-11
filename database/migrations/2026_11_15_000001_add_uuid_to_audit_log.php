<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * La consulta de auditoría (AU-1) expone un id público por entrada. Las escritas antes no lo tienen y
 * la API les deriva uno del _id, así que el índice solo cubre las que sí lo llevan.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->table('audit_log', function (Blueprint $c) {
            $c->unique(['uuid' => 1], 'audit_log_uuid', options: ['partialFilterExpression' => ['uuid' => ['$exists' => true]]]);
            // La consulta global también busca lo que no es de ninguna sucursal, por fecha.
            $c->index(['created_at' => -1]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->table('audit_log', function (Blueprint $c) {
            $c->dropIndex('audit_log_uuid');
            $c->dropIndex(['created_at' => -1]);
        });
    }
};
