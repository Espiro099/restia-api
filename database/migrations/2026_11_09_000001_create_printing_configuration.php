<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Identidades de servicio (D-36, sección 11; PR-12) y configuración de impresión: impresoras,
 * rutas y agentes (Decisiones_Printing, sección 7). Guía, sección 8.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    private const COLLECTIONS = ['service_identities', 'printers', 'print_stations', 'print_agents'];

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->create('service_identities', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique('name');
            // Parciales: una identidad revocada ya no guarda hashes, y el anterior solo existe tras rotar.
            $c->unique('token_hash', 'token_hash_unico', options: [
                'partialFilterExpression' => ['token_hash' => ['$type' => 'string']],
            ]);
            $c->index('previous_token_hash', 'previous_token_hash', options: [
                'partialFilterExpression' => ['previous_token_hash' => ['$type' => 'string']],
            ]);
        });

        foreach (['printers', 'print_stations'] as $name) {
            $schema->create($name, function (Blueprint $c) {
                $c->unique('uuid');
                $c->unique(['branch_id' => 1, 'code' => 1]);
            });
        }

        $schema->create('print_agents', function (Blueprint $c) {
            $c->unique('uuid');
            // Una identidad admite un solo agente.
            $c->unique('service_identity_id');
            $c->index(['branch_id' => 1, 'status' => 1]);
        });
    }

    public function down(): void
    {
        foreach (self::COLLECTIONS as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
