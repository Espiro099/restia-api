<?php

namespace App\Fiscal\Pac;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * PAC simulado para desarrollo y pruebas, mientras no se elige proveedor. Revisa el CSD como lo haría
 * un PAC (certificado legible y vigente, del mismo RFC, llave que abre con la contraseña y corresponde
 * al certificado), pero no habla con nadie. Nunca corre en producción.
 */
final class SimulatedPac implements Pac
{
    public function __construct()
    {
        if (app()->isProduction()) {
            throw new RuntimeException('El PAC simulado no se usa en producción: configura RESTIA_PAC.');
        }
    }

    public function registerCsd(string $rfc, string $certificate, string $privateKey, string $password): CsdInfo
    {
        $cert = openssl_x509_read(self::pem($certificate, 'CERTIFICATE'));
        $info = $cert ? openssl_x509_parse($cert) : false;
        if (! $info) {
            throw new CsdRejected('Certificado ilegible.');
        }
        // El SAT pone el RFC en x500UniqueIdentifier ("RFC / RFC del representante").
        $owner = (string) ($info['subject']['x500UniqueIdentifier'] ?? '');
        if ($owner !== '' && strtoupper(trim(explode('/', $owner)[0])) !== strtoupper($rfc)) {
            throw new CsdRejected('El certificado es de otro RFC.');
        }
        $validUntil = CarbonImmutable::createFromTimestampUTC((int) $info['validTo_time_t']);
        if ($validUntil->isPast() || CarbonImmutable::createFromTimestampUTC((int) $info['validFrom_time_t'])->isFuture()) {
            throw new CsdRejected('El certificado no está vigente.');
        }
        $key = openssl_pkey_get_private(self::pem($privateKey, 'ENCRYPTED PRIVATE KEY'), $password);
        if (! $key || ! openssl_x509_check_private_key($cert, $key)) {
            throw new CsdRejected('La llave no abre con la contraseña o no corresponde al certificado.');
        }

        return new CsdInfo('sim-'.substr(hash('sha256', $certificate), 0, 24), self::serial($info), $validUntil);
    }

    /** Los archivos del SAT vienen en DER; también se aceptan en PEM. */
    private static function pem(string $content, string $label): string
    {
        return str_contains($content, '-----BEGIN')
            ? $content
            : "-----BEGIN $label-----\n".chunk_split(base64_encode($content), 64, "\n")."-----END $label-----\n";
    }

    /** El número del SAT son 20 dígitos codificados como texto dentro del número de serie. */
    private static function serial(array $info): string
    {
        $hex = (string) ($info['serialNumberHex'] ?? '');
        $text = strlen($hex) % 2 === 0 ? (string) hex2bin($hex) : '';

        return ctype_digit($text) && $text !== '' ? $text : $hex;
    }
}
