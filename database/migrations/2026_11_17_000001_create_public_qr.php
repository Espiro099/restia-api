<?php

use App\PublicQr\PublicQr;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Public QR (Decisiones_Public_QR, sección 7): token anterior, señales de presencia y tokens de las mesas existentes. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->table('tables', function (Blueprint $c) {
            // Un token en gracia tampoco puede repetirse en otra mesa.
            $c->unique('previous_qr_token', 'previous_qr_token_unico', options: [
                'partialFilterExpression' => ['previous_qr_token' => ['$type' => 'string']],
            ]);
        });

        $schema->create('table_presence_signals', function (Blueprint $c) {
            $c->unique(['branch_id' => 1, 'table_id' => 1]);
            // TTL: la señal vencida desaparece sola y la colección no crece (QR-8).
            $c->index(['expires_at' => 1], options: ['expireAfterSeconds' => 0]);
        });

        // Las mesas creadas antes de Public QR reciben su token.
        foreach (DB::connection('mongodb')->table('tables')->whereNull('qr_token')->get(['_id']) as $row) {
            $row = (array) $row;
            DB::connection('mongodb')->table('tables')->where('_id', $row['_id'] ?? $row['id'])->update(['qr_token' => PublicQr::newToken()]);
        }
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('table_presence_signals');
        Schema::connection('mongodb')->table('tables', function (Blueprint $c) {
            $c->dropIndex('previous_qr_token_unico');
        });
    }
};
