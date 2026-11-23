<?php

namespace App\Http\Controllers;

use App\Models\ProductImage;
use App\Support\Floor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Fotos del menú (M-12), sin sesión: se ven en el menú QR público, en el panel y en la app. El uuid cambia
 * con cada foto nueva, así que el navegador y la app la guardan en caché sin volver a preguntar.
 */
class ProductImageController extends Controller
{
    public function show(Request $request, string $imageId): Response
    {
        $image = ProductImage::query()->where('uuid', Floor::uuid($imageId))->first() ?? throw new NotFoundHttpException;
        $etag = '"'.$image->uuid.'"';
        $headers = [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            // El panel y el menú QR se abren desde otro dominio que la API.
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ];
        $sent = preg_replace('/-(?:br|gzip|zstd|deflate)"$/', '"', trim((string) $request->header('If-None-Match')));
        if ($sent === $etag) {
            return response('', 304, $headers);
        }

        return response($image->data->getData(), 200, $headers);
    }
}
