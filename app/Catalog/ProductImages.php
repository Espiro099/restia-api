<?php

namespace App\Catalog;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectId;

/**
 * Foto del producto (M-12). La API no guarda el archivo tal como llega: lo abre con GD, lo reduce a
 * 1200 px por lado como máximo y lo vuelve a codificar como JPEG. Así se quitan los metadatos (EXIF, la
 * ubicación del teléfono) y cualquier cosa que no sea la imagen. Solo JPEG, PNG y WebP; nada de SVG.
 */
final class ProductImages
{
    /** Tamaño máximo de la carga. El panel ya reduce la foto antes de subirla. */
    public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    public const MAX_SIDE = 1200;

    private const MIN_SIDE = 100;

    /** Ancho por alto máximo del original, para no descomprimir una "bomba" de píxeles. */
    private const MAX_PIXELS = 40_000_000;

    private const QUALITY = 82;

    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public static function url(Product $p): ?string
    {
        $uuid = $p->image_uuid;

        return $uuid ? rtrim((string) config('app.url'), '/')."/api/v1/public/images/$uuid" : null;
    }

    /** Cambia la foto del producto; la anterior se borra en la misma transacción. */
    public static function store(Branch $branch, User $actor, Product $p, int $version, string $upload): Product
    {
        [$jpeg, $width, $height] = self::process($upload);
        $uuid = (string) Str::uuid();
        $old = null;

        return Catalog::change($branch, $actor, $p, $version, 'product.image_changed', 'product', function (Product $fresh) use ($uuid, &$old) {
            $old = $fresh->image_uuid;

            return ['image_uuid' => $uuid];
        }, after: function (Product $fresh, ObjectId $branchId) use ($uuid, $jpeg, $width, $height, &$old) {
            ProductImage::query()->create([
                'uuid' => $uuid, 'branch_id' => $branchId, 'product_id' => new ObjectId($fresh->getKey()),
                'content_type' => 'image/jpeg', 'data' => new Binary($jpeg, Binary::TYPE_GENERIC),
                'bytes' => strlen($jpeg), 'width' => $width, 'height' => $height,
            ]);
            if ($old) {
                ProductImage::query()->where('uuid', $old)->delete();
            }

            return ['before' => [], 'after' => ['image_width' => $width, 'image_height' => $height, 'image_bytes' => strlen($jpeg)]];
        });
    }

    /**
     * @return array{0: string, 1: int, 2: int} JPEG nuevo, ancho y alto
     */
    public static function process(string $upload): array
    {
        $info = @getimagesizefromstring($upload);
        if (! $info || ! in_array($info[2], self::TYPES, true)) {
            self::invalid('La foto debe ser JPG, PNG o WebP.');
        }
        [$w, $h] = $info;
        if ($w < self::MIN_SIDE || $h < self::MIN_SIDE) {
            self::invalid('La foto debe medir al menos '.self::MIN_SIDE.' píxeles por lado.');
        }
        if ($w * $h > self::MAX_PIXELS) {
            self::invalid('La foto es demasiado grande. Usa una de menos de 40 megapíxeles.');
        }
        $source = @imagecreatefromstring($upload);
        if ($source === false) {
            self::invalid('No se pudo leer la foto.');
        }

        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $width = max(1, (int) round($w * $scale));
        $height = max(1, (int) round($h * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        // Lo transparente de un PNG o WebP queda en blanco: JPEG no tiene transparencia.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $w, $h);
        imageinterlace($canvas, true);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);

        return [(string) ob_get_clean(), $width, $height];
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['image' => $message]);
    }
}
