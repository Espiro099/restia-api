<?php

namespace App\Support;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MongoDB\BSON\Decimal128;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Ayudas comunes del servicio en piso: fecha operativa, dinero, UUID de la ruta y ETag de lectura. */
final class Floor
{
    public const CURRENCY = 'MXN';

    /** `business_date`: fecha local de la sucursal, que cambia en `business_day_cutoff` (B-6). */
    public static function businessDate(Branch $branch, ?CarbonInterface $at = null): string
    {
        $local = Carbon::instance($at ?? now())->setTimezone($branch->timezone ?: 'America/Mexico_City');
        $cutoff = $branch->business_day_cutoff ?: config('restia.floor.business_day_cutoff');
        if ($local->format('H:i') < $cutoff) {
            $local->subDay();
        }

        return $local->format('Y-m-d');
    }

    /** Money del contrato. El monto sale como string con dos decimales (D-24). */
    public static function money(Decimal128|string|int|float|null $amount, ?string $currency = null): array
    {
        return ['amount' => bcadd((string) ($amount ?? '0'), '0', 2), 'currency' => $currency ?: self::CURRENCY];
    }

    public static function decimal(string $amount): Decimal128
    {
        return new Decimal128(bcadd($amount, '0', 2));
    }

    /** Un UUID mal formado o de otra sucursal responde 404, igual que uno inexistente. */
    public static function uuid(string $value): string
    {
        return Str::isUuid($value) ? strtolower($value) : throw new NotFoundHttpException;
    }

    /**
     * Lectura con ETag: si el cliente ya tiene esta versión, 304 sin cuerpo. El ETag de una lista es
     * la huella de lo que se respondería; el de un recurso, su versión.
     */
    public static function withEtag(Request $request, mixed $data, array $meta = [], ?string $etag = null): Response
    {
        $etag ??= '"'.substr(hash('sha256', json_encode([$data, $meta], JSON_UNESCAPED_UNICODE)), 0, 32).'"';
        if (self::matches($request, $etag)) {
            return new Response('', 304, ['ETag' => $etag]);
        }
        $response = Api::data($data, meta: $meta);
        $response->headers->set('ETag', $etag);

        return $response;
    }

    /**
     * Versión del header If-Match: acepta `"8"`, `W/"8"` y `8`. Sin el header responde
     * 400 VERSION_REQUIRED; con un valor que no es una versión, 409 VERSION_CONFLICT.
     */
    public static function ifMatch(Request $request): int
    {
        $sent = trim((string) $request->headers->get('If-Match', ''));
        if ($sent === '') {
            throw new DomainConflict('VERSION_REQUIRED');
        }
        if (! preg_match('/^(?:W\/)?"?([1-9][0-9]{0,9})"?$/', $sent, $m)) {
            throw new DomainConflict('VERSION_CONFLICT');
        }

        return (int) $m[1];
    }

    public static function versionEtag(int $version): string
    {
        return '"'.$version.'"';
    }

    private static function matches(Request $request, string $etag): bool
    {
        $sent = (string) $request->headers->get('If-None-Match', '');
        if ($sent === '') {
            return false;
        }
        foreach (explode(',', $sent) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/'.$etag) {
                return true;
            }
        }

        return false;
    }
}
