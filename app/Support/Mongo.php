<?php

namespace App\Support;

use App\Exceptions\ServiceBusy;
use Closure;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\RuntimeException;
use Throwable;

/** Ayudas de MongoDB verificadas en la prueba técnica (docs/Guia_Base_de_Datos_MongoDB.md, sección 6). */
final class Mongo
{
    public const ATTEMPTS = 10;

    /**
     * Transacción con hasta 10 intentos y espera aleatoria. Nunca usar transaction() sin intentos.
     *
     * Dentro de $work, escribir solo con modelos o DB::table(): getCollection() no lleva la sesión,
     * así que esa escritura queda fuera de la transacción y no se deshace (MongoTest lo comprueba).
     */
    public static function transaction(Closure $work): mixed
    {
        $attempt = 0;
        try {
            return DB::connection('mongodb')->transaction(function () use ($work, &$attempt) {
                if ($attempt++ > 0) {
                    usleep(random_int(0, (int) min(200_000, 5_000 * 2 ** $attempt)));
                }

                return $work();
            }, self::ATTEMPTS);
        } catch (Throwable $e) {
            if (self::isTransient($e)) {
                throw new ServiceBusy(previous: $e);
            }
            throw $e;
        }
    }

    public static function isTransient(Throwable $e): bool
    {
        return $e instanceof RuntimeException && $e->hasErrorLabel('TransientTransactionError');
    }

    /** E11000: un índice único rechazó la escritura. La API lo traduce a 409 con el código de la operación. */
    public static function isDuplicateKey(Throwable $e): bool
    {
        if ($e instanceof BulkWriteException) {
            foreach ($e->getWriteResult()->getWriteErrors() as $error) {
                if ($error->getCode() === 11000) {
                    return true;
                }
            }
        }

        return $e->getCode() === 11000;
    }
}
