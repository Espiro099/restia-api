<?php

namespace App\Models;

use App\Models\Casts\ObjectId;

/** Versión de la receta de un producto. Inmutable: publicar otra deja esta en SUPERSEDED. */
class Recipe extends Document
{
    public const ACTIVE = 'ACTIVE';

    public const SUPERSEDED = 'SUPERSEDED';

    protected $table = 'recipes';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'product_id' => ObjectId::class,
        'created_by' => ObjectId::class,
    ];
}
