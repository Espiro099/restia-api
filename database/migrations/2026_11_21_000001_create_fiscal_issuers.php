<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Emisores fiscales (F-13). Un RFC distinto es otro emisor. */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('fiscal_issuers', function (Blueprint $c) {
            $c->unique('uuid');
            $c->unique('rfc');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('fiscal_issuers');
    }
};
