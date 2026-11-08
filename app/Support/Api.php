<?php

namespace App\Support;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Envoltura `{data, meta}` del contrato y validación estricta de entradas. */
final class Api
{
    /** @param array<string, mixed> $meta */
    public static function data(mixed $data, int $status = 200, array $meta = [], bool $noStore = false): JsonResponse
    {
        $response = new JsonResponse(['data' => $data, 'meta' => ['requestId' => AssignRequestId::current()] + $meta], $status);
        if ($noStore) {
            // Respuestas con tokens o códigos (D-36, sección 8).
            $response->headers = new NoStoreHeaders($response->headers->all());
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }

    public static function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * Valida el cuerpo JSON y rechaza campos no declarados (`additionalProperties: false`).
     * Cada regla de texto exige `string`, para que un arreglo no llegue a una consulta (prueba 9).
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function body(Request $request, array $rules): array
    {
        $input = $request->json()->all();
        $extra = array_diff(array_keys($input), array_keys($rules));
        $validator = Validator::make($input, $rules);
        $validator->after(function ($v) use ($extra) {
            foreach ($extra as $field) {
                $v->errors()->add((string) $field, 'Campo no permitido.');
            }
        });

        return $validator->validate();
    }

    /**
     * Página y tamaño de página (parámetros Page y PerPage del contrato).
     *
     * @return array{0: int, 1: int}
     */
    public static function page(Request $request): array
    {
        $v = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }

        return [(int) $request->query('page', 1), (int) $request->query('perPage', 25)];
    }

    /** @return array{pagination: array{page: int, perPage: int, total: int, lastPage: int}} */
    public static function pagination(int $page, int $perPage, int $total): array
    {
        return ['pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'lastPage' => max(1, (int) ceil($total / $perPage)),
        ]];
    }
}
