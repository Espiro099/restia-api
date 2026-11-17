<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use Illuminate\Support\Facades\Crypt;

/**
 * Cliente (CU-1). Cada sucursal solo ve los clientes con perfil en ella, y el mismo teléfono en otra
 * sucursal es otra identidad (CU-2). Sin datos fiscales guardados.
 */
class Customer extends Document
{
    use HasVersion;

    /** Nombre que queda al anonimizar (CU-4). */
    public const ANONYMIZED_NAME = 'Cliente anonimizado';

    protected $table = 'customers';

    /**
     * Teléfono y correo se guardan cifrados con AES-256-CBC y HMAC (cipher de config/app.php). Para
     * buscar por teléfono exacto se guarda aparte `phone_index`, un HMAC-SHA256 que no permite
     * recuperar el número.
     */
    protected $casts = [
        'anonymized_at' => 'datetime',
        'phone' => 'encrypted',
        'email' => 'encrypted',
    ];

    /** Columnas cifradas en reposo. */
    public const ENCRYPTED = ['phone', 'email'];

    /** Índice ciego del teléfono: mismo número, mismo valor; sin la clave no se puede revertir. */
    public static function phoneIndex(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        $key = hash_hmac('sha256', 'restia-customer-phone-index', (string) config('app.key'));

        return hash_hmac('sha256', $phone, $key);
    }

    /**
     * Cambios para una actualización directa (updateIfVersion no pasa por los casts): cifra teléfono y
     * correo y recalcula el índice.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function encryptChanges(array $changes): array
    {
        if (array_key_exists('phone', $changes)) {
            $changes['phone_index'] = self::phoneIndex($changes['phone']);
        }
        foreach (self::ENCRYPTED as $column) {
            if (array_key_exists($column, $changes) && $changes[$column] !== null) {
                $changes[$column] = Crypt::encryptString((string) $changes[$column]);
            }
        }

        return $changes;
    }
}
