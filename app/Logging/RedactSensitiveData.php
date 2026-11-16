<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Ningún log guarda credenciales ni datos de tarjeta (CRED-24, Revision_Seguridad sección 3). Se aplica
 * como `tap` a los canales de config/logging.php: reemplaza el valor de toda clave sensible del contexto
 * y enmascara en el mensaje lo que parezca un número de tarjeta, un Bearer o un correo.
 */
class RedactSensitiveData
{
    public const MASK = '[REDACTADO]';

    /** Claves cuyo valor nunca se registra, compare como se compare (mayúsculas, guiones, camelCase). */
    private const SENSITIVE = '/pass(word)?|token|secret|authorization|cookie|xsrf|csrf|credential|private_?key|csd|api_?key|card|cvv|pan$|rfc|curp|phone|email/i';

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(fn (LogRecord $record) => self::redactRecord($record));
        }
    }

    public static function redactRecord(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::redactText($record->message),
            context: self::redactArray($record->context),
            extra: self::redactArray($record->extra),
        );
    }

    /** @param array<mixed> $data */
    public static function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::redactArray($value);
            } elseif (is_string($value)) {
                $data[$key] = self::redactText($value);
            }
        }

        return $data;
    }

    public static function redactText(string $text): string
    {
        // Tokens Bearer y de Sanctum (id|texto).
        $text = (string) preg_replace('/Bearer\s+[A-Za-z0-9._|~+\/=-]+/i', 'Bearer '.self::MASK, $text);
        // 13 a 19 dígitos seguidos, con espacios o guiones: posible número de tarjeta.
        $text = (string) preg_replace('/\b(?:\d[ -]?){12,18}\d\b/', self::MASK, $text);

        // Correos: se conserva solo el dominio.
        return (string) preg_replace('/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', '…@$1', $text);
    }
}
