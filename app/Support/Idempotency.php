<?php

namespace App\Support;

use App\Exceptions\DomainConflict;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Throwable;

/**
 * Idempotency-Key (CON-1, sección 32 del documento maestro). El registro se escribe en la misma transacción
 * que el cambio y dura 72 horas. La misma clave con los mismos datos, del mismo usuario y operación,
 * devuelve la respuesta original; cualquier otra combinación recibe IDEMPOTENCY_KEY_REUSED.
 */
final class Idempotency
{
    public const HEADER = 'Idempotency-Key';

    /** El contrato la declara obligatoria y con formato UUID. */
    public static function key(Request $request): string
    {
        $key = (string) $request->headers->get(self::HEADER, '');
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages([self::HEADER => 'Se requiere un UUID en el header Idempotency-Key.']);
        }

        return strtolower($key);
    }

    /** Huella del cuerpo ya validado, sin depender del orden de las claves. */
    public static function hash(string $operation, array $body): string
    {
        return hash('sha256', $operation.'|'.json_encode(self::canonical($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Ejecuta $work en una transacción, salvo que la clave ya tenga respuesta. $work devuelve
     * [estado, data, id del recurso o null]. Devuelve [estado, data].
     *
     * Si dos peticiones con la misma clave llegan a la vez, la segunda choca con el índice único al guardar
     * el registro; se repite una vez y entonces devuelve la respuesta de la primera. Un choque de otro índice
     * único también se repite una vez, para que la operación vea el dato nuevo y responda con su propio código.
     *
     * @return array{0: int, 1: mixed}
     */
    public static function run(ObjectId $branchId, string $key, ObjectId $actorId, string $operation, string $hash, Closure $work): array
    {
        $attempt = function () use ($branchId, $key, $actorId, $operation, $hash, $work) {
            return Mongo::transaction(function () use ($branchId, $key, $actorId, $operation, $hash, $work) {
                $db = DB::connection('mongodb')->table('idempotency_records');
                $previous = $db->where('branch_id', $branchId)->where('key', $key)->first();
                if ($previous) {
                    $previous = (array) $previous;
                    $same = $previous['request_hash'] === $hash
                        && (string) $previous['actor_id'] === (string) $actorId
                        && $previous['operation_id'] === $operation;
                    if (! $same) {
                        throw new DomainConflict('IDEMPOTENCY_KEY_REUSED');
                    }

                    return [(int) $previous['status_code'], json_decode($previous['response_body'], true)];
                }

                [$status, $data, $resourceId] = $work();
                $now = now();
                DB::connection('mongodb')->table('idempotency_records')->insert([
                    'branch_id' => $branchId,
                    'key' => $key,
                    'actor_id' => $actorId,
                    'operation_id' => $operation,
                    'request_hash' => $hash,
                    'status_code' => $status,
                    'response_body' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'resource_id' => $resourceId,
                    'created_at' => new UTCDateTime($now),
                    'expires_at' => new UTCDateTime($now->copy()->addHours(config('restia.floor.idempotency_hours'))),
                ]);

                return [$status, $data];
            });
        };

        try {
            return $attempt();
        } catch (Throwable $e) {
            if (! Mongo::isDuplicateKey($e)) {
                throw $e;
            }

            return $attempt();
        }
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
