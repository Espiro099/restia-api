<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Middleware\AuthorizeBranch;
use App\Models\Branch;
use App\Models\Document;
use App\Support\Api;
use App\Support\Floor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Lista, búsqueda, respuesta con ETag y cuerpos comunes de los recursos que se administran por sucursal. */
trait BranchResources
{
    // Hasta dos decimales con contenido: la moneda de la sucursal no tiene fracciones menores al centavo.
    private const AMOUNT = 'regex:/^\d{1,12}(\.\d{1,2}0{0,2})?$/';

    /**
     * Reglas de un Money del contrato, en la moneda de la sucursal. Con $positive, mayor que 0.
     *
     * @return array<string, mixed>
     */
    protected function money(string $field, bool $required, bool $signed = false, bool $positive = false): array
    {
        $amount = ['string', $signed ? 'regex:/^-?\d{1,12}(\.\d{1,2}0{0,2})?$/' : self::AMOUNT];
        if ($positive) {
            $amount[] = 'not_regex:/^0+(\.0+)?$/';
        }

        return [
            $field => [$required ? 'required' : 'sometimes', 'array:amount,currency'],
            "$field.amount" => [$required ? 'required' : "required_with:$field", ...$amount],
            "$field.currency" => [$required ? 'required' : "required_with:$field", 'string', Rule::in([Floor::CURRENCY])],
        ];
    }

    /**
     * @param  class-string<Document>  $model
     * @param  list<string>  $order
     */
    protected function list(Request $request, string $model, array $order, \Closure $present, bool $status = true): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $v = Validator::make($request->query(), [
            'status' => $status ? ['sometimes', 'string', Rule::in(['ACTIVE', 'INACTIVE'])] : ['prohibited'],
            'page' => ['sometimes'], 'perPage' => ['sometimes'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $branch = AuthorizeBranch::branch($request);
        $query = $model::query()->forBranch($branch);
        if ($request->query('status') !== null) {
            $query->where('status', $request->query('status'));
        }
        $total = (clone $query)->count();
        foreach ($order as $field) {
            $query->orderBy($field);
        }
        $rows = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data($present($branch, $rows), meta: Api::pagination($page, $perPage, $total));
    }

    /**
     * @template T of Document
     *
     * @param  class-string<T>  $model
     * @return array{0: Branch, 1: T}
     */
    protected function find(Request $request, string $model, string $id): array
    {
        $branch = AuthorizeBranch::branch($request);

        return [$branch, $model::query()->forBranch($branch)->where('uuid', Floor::uuid($id))->first() ?? throw new NotFoundHttpException];
    }

    /** @param array<string, mixed> $data */
    protected function one(array $data, Document $doc, int $status = 200): JsonResponse
    {
        $response = Api::data($data, $status);
        $response->headers->set('ETag', Floor::versionEtag((int) $doc->version));

        return $response;
    }

    /** `minProperties: 1`: un PATCH vacío no tiene nada que cambiar. */
    protected function patchBody(Request $request, array $rules): array
    {
        $in = Api::body($request, $rules);
        if (! $in) {
            throw ValidationException::withMessages(['body' => 'Indica al menos un campo.']);
        }

        return $in;
    }

    /** @param list<string> $statuses */
    protected function statusBody(Request $request, array $statuses): array
    {
        return Api::body($request, [
            'status' => ['required', 'string', Rule::in($statuses)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
    }
}
