<?php

namespace App\Models;

use App\Models\Casts\ObjectId;

/**
 * Foto de un producto del menú (M-12). Se guarda en MongoDB y no en disco: el servicio en Render no tiene
 * disco persistente. Es un JPEG ya procesado por la API (sin metadatos, 1200 px por lado como máximo).
 * La sirve GET /public/images/{uuid}; cada foto nueva tiene otro uuid, así que una URL nunca cambia de contenido.
 */
class ProductImage extends Document
{
    protected $table = 'product_images';

    protected $hidden = ['data'];

    protected $casts = [
        'branch_id' => ObjectId::class,
        'product_id' => ObjectId::class,
        'bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];
}
