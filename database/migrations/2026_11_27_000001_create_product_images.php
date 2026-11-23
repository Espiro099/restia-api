<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Fotos de los productos del menú (M-12). */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('product_images', function (Blueprint $c) {
            $c->unique('uuid');
            $c->index(['branch_id' => 1, 'product_id' => 1]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('product_images');
    }
};
