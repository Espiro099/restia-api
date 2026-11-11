<?php

namespace App\Http\Controllers;

use App\Customers\Customers;
use App\Http\Middleware\AuthorizeBranch;
use App\Models\Customer;
use App\Models\CustomerBranchProfile;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Clientes por sucursal (CU-1 a CU-4). */
class CustomerController extends Controller
{
    private const PHONE = 'regex:/^\+[1-9][0-9]{7,14}$/';

    private const FIELDS = [
        'name' => ['string', 'min:1', 'max:120'],
        'phone' => ['string', self::PHONE],
        'email' => ['string', 'email', 'max:254'],
        'notes' => ['string', 'max:500'],
        'preferences' => ['array', 'list', 'max:50'],
        'preferences.*' => ['string', 'max:60'],
    ];

    public function create(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, ['name' => ['required', ...self::FIELDS['name']], 'phone' => ['required', ...self::FIELDS['phone']]]
            + array_map(fn ($r) => ['sometimes', ...$r], array_diff_key(self::FIELDS, ['name' => 0, 'phone' => 0])));
        [$status, $data] = Customers::create(AuthorizeBranch::branch($request), $request->user(), $key, $in,
            fn (Customer $c, CustomerBranchProfile $p) => Customers::present($c, $p));

        return Api::data($data, $status);
    }

    /** CU-3: por POST para que el teléfono no quede en URLs ni en logs de acceso. */
    public function search(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $in = Api::body($request, ['phone' => ['sometimes', 'string', self::PHONE], 'name' => ['sometimes', 'string', 'min:2', 'max:120']]);
        if (! $in) {
            throw ValidationException::withMessages(['phone' => 'Busca por teléfono o por nombre.']);
        }
        [$rows, $total] = Customers::search(AuthorizeBranch::branch($request), $in, $page, $perPage);

        return Api::data($rows->map(fn ($r) => Customers::present(...$r))->values()->all(), meta: Api::pagination($page, $perPage, $total));
    }

    public function show(Request $request, string $branchId, string $customerId): JsonResponse
    {
        [$customer, $profile] = Customers::find(AuthorizeBranch::branch($request), Floor::uuid($customerId));

        return $this->etagged(Customers::present($customer, $profile), $customer);
    }

    public function update(Request $request, string $branchId, string $customerId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, array_map(fn ($r) => ['sometimes', ...$r], self::FIELDS));
        if (! $in) {
            throw ValidationException::withMessages(['name' => 'Indica al menos un campo.']);
        }
        $branch = AuthorizeBranch::branch($request);
        [$customer, $profile] = Customers::find($branch, Floor::uuid($customerId));
        $customer = Customers::update($branch, $request->user(), $customer, $profile, $version, $in);

        return $this->etagged(Customers::present($customer, Customers::profile($branch, $customer)), $customer);
    }

    public function anonymize(Request $request, string $branchId, string $customerId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $branch = AuthorizeBranch::branch($request);
        [$customer] = Customers::find($branch, Floor::uuid($customerId));
        $customer = Customers::anonymize($branch, $request->user(), $customer, $version, $in['reason']);

        return $this->etagged(Customers::present($customer, Customers::profile($branch, $customer)), $customer);
    }

    private function etagged(array $data, Customer $customer): JsonResponse
    {
        $response = Api::data($data);
        $response->headers->set('ETag', Floor::versionEtag((int) $customer->version));

        return $response;
    }
}
