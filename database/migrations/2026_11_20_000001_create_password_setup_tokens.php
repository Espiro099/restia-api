<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Enlaces de contraseña de un solo uso (U-2), guardados como hash. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('password_setup_tokens', function (Blueprint $c) {
            $c->unique('token_hash');
            $c->index('user_id');
            // TTL: un enlace vencido ya no sirve y desaparece solo.
            $c->index(['expires_at' => 1], options: ['expireAfterSeconds' => 0]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('password_setup_tokens');
    }
};
