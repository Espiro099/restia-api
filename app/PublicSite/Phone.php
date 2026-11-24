<?php

namespace App\PublicSite;

/** Teléfonos del sitio público: la persona escribe 10 dígitos; la API guarda E.164 (+52…), como el personal. */
final class Phone
{
    /** "+523312345678" a partir de "33 1234 5678", "+52 33…" o "523312345678"; null si no es un número válido. */
    public static function e164(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 10) {
            return '+52'.$digits;
        }
        if (str_starts_with($digits, '52') && strlen($digits) === 12) {
            return '+'.$digits;
        }
        if (str_starts_with($raw, '+') && preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            return '+'.$digits;
        }

        return null;
    }

    /** Los 10 dígitos de un número mexicano, para mostrarlo en el formulario; null si no sirve. */
    public static function national(?string $raw): ?string
    {
        $e164 = self::e164($raw);

        return $e164 && str_starts_with($e164, '+52') ? substr($e164, 3) : null;
    }
}
