<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** GOOGLE-3: una cuenta de Google por usuario. Parcial: quien no la ha usado no choca con nadie. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->table('users', function (Blueprint $c) {
            $c->unique('google_sub', 'google_sub_unico', options: [
                'partialFilterExpression' => ['google_sub' => ['$type' => 'string']],
            ]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->table('users', function (Blueprint $c) {
            $c->dropIndex('google_sub_unico');
        });
    }
};
