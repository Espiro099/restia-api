<?php

namespace App\Customers;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranchProfile;
use App\Models\User;
use App\Support\Audit;
use App\Support\Idempotency;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Clientes (Decisiones_Modulos_Restantes, sección 3). Datos básicos globales y un perfil por sucursal.
 * Los datos personales nunca van completos a la auditoría: se enmascaran al escribirse (sección 5).
 */
final class Customers
{
    private const PROFILE_FIELDS = ['notes' => 'notes', 'preferences' => 'preferences'];

    private const CUSTOMER_FIELDS = ['name' => 'name', 'phone' => 'phone', 'email' => 'email'];

    public static function present(Customer $c, ?CustomerBranchProfile $p): array
    {
        $time = fn ($t) => $t ? Carbon::instance($t)->utc()->format('Y-m-d\TH:i:s\Z') : null;

        return [
            'id' => $c->uuid,
            'name' => $c->name,
            'phone' => $c->phone,
            'email' => $c->email,
            'anonymized' => $c->anonymized_at !== null,
            'profile' => [
                'notes' => $p?->notes,
                'preferences' => array_values($p?->preferences ?? []),
                'visitCount' => (int) ($p?->visit_count ?? 0),
                'lastVisitAt' => $time($p?->last_visit_at),
            ],
            'version' => (int) $c->version,
        ];
    }

    /** CU-2: un cliente nuevo en cada sucursal, aunque el teléfono exista en otra. */
    public static function create(Branch $branch, User $actor, string $key, array $in, \Closure $present): array
    {
        if (! $branch->isActive()) {
            throw new DomainConflict('BRANCH_INACTIVE');
        }
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        return Idempotency::run($branchId, $key, $actorId, 'createCustomer', Idempotency::hash('createCustomer', $in), function () use ($branchId, $actorId, $in, $present) {
            $customer = Customer::query()->create([
                'name' => $in['name'], 'phone' => $in['phone'], 'email' => $in['email'] ?? null, 'anonymized_at' => null,
            ]);
            $profile = CustomerBranchProfile::query()->create([
                'branch_id' => $branchId, 'customer_id' => new ObjectId($customer->getKey()),
                'notes' => $in['notes'] ?? null, 'preferences' => array_values($in['preferences'] ?? []), 'visit_count' => 0, 'last_visit_at' => null,
            ]);
            Audit::record('customer.created', 'customer', $customer->getKey(), $branchId, $actorId, changes: ['after' => self::masked($in)]);

            return [201, $present($customer, $profile), $customer->uuid];
        });
    }

    /** Cliente y su perfil en esta sucursal; sin perfil, 404 (CU-2). @return array{0: Customer, 1: CustomerBranchProfile} */
    public static function find(Branch $branch, string $uuid): array
    {
        $customer = Customer::query()->where('uuid', strtolower($uuid))->first();
        $profile = $customer ? self::profile($branch, $customer) : null;

        return $profile ? [$customer, $profile] : throw new NotFoundHttpException;
    }

    public static function profile(Branch $branch, Customer $customer): ?CustomerBranchProfile
    {
        return CustomerBranchProfile::query()->where('branch_id', new ObjectId($branch->getKey()))
            ->where('customer_id', new ObjectId($customer->getKey()))->first();
    }

    /**
     * CU-3: por teléfono exacto o por parte del nombre, solo entre los clientes de la sucursal. Los
     * anonimizados no aparecen.
     *
     * @return array{0: Collection<int, array{0: Customer, 1: CustomerBranchProfile}>, 1: int}
     */
    public static function search(Branch $branch, array $in, int $page, int $perPage): array
    {
        $profiles = CustomerBranchProfile::query()->where('branch_id', new ObjectId($branch->getKey()))->get()->keyBy(fn ($p) => (string) $p->customer_id);
        $query = Customer::query()->whereIn('_id', $profiles->keys()->map(fn ($id) => new ObjectId($id))->all())->whereNull('anonymized_at');
        if (isset($in['phone'])) {
            $query->where('phone', $in['phone']);
        }
        if (isset($in['name'])) {
            $query->where('name', 'regex', new Regex(preg_quote($in['name'], '/'), 'i'));
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('name')->orderBy('_id')->skip(($page - 1) * $perPage)->take($perPage)->get()
            ->map(fn (Customer $c) => [$c, $profiles->get((string) $c->getKey())]);

        return [$rows, $total];
    }

    public static function update(Branch $branch, User $actor, Customer $customer, CustomerBranchProfile $profile, int $version, array $in): Customer
    {
        if ($customer->anonymized_at) {
            throw new DomainConflict('CUSTOMER_ANONYMIZED');
        }
        $branchId = new ObjectId($branch->getKey());
        Mongo::transaction(function () use ($customer, $profile, $version, $in, $branchId, $actor) {
            $fresh = Customer::query()->find($customer->getKey());
            if ($fresh->anonymized_at) {
                throw new DomainConflict('CUSTOMER_ANONYMIZED');
            }
            $before = [];
            $changes = [];
            foreach (self::CUSTOMER_FIELDS as $field => $column) {
                if (array_key_exists($field, $in)) {
                    $before[$field] = $fresh->{$column};
                    $changes[$column] = $in[$field];
                }
            }
            if (! $fresh->updateIfVersion($version, $changes)) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            $profileChanges = array_intersect_key($in, self::PROFILE_FIELDS);
            if ($profileChanges) {
                CustomerBranchProfile::query()->where('_id', new ObjectId($profile->getKey()))->update($profileChanges + ['updated_at' => now()]);
            }
            Audit::record('customer.updated', 'customer', $fresh->getKey(), $branchId, new ObjectId($actor->getKey()),
                changes: ['before' => self::masked($before), 'after' => self::masked($in)]);
        });

        return $customer->refresh();
    }

    /**
     * CU-4: reemplaza nombre, teléfono, correo, notas y preferencias, también en las copias de las
     * reservas. Las facturas no cambian. La auditoría guarda el motivo, no los datos borrados.
     */
    public static function anonymize(Branch $branch, User $actor, Customer $customer, int $version, string $reason): Customer
    {
        if ($customer->anonymized_at) {
            throw new DomainConflict('CUSTOMER_ANONYMIZED');
        }
        $branchId = new ObjectId($branch->getKey());
        Mongo::transaction(function () use ($customer, $version, $reason, $branchId, $actor) {
            $fresh = Customer::query()->find($customer->getKey());
            if ($fresh->anonymized_at) {
                throw new DomainConflict('CUSTOMER_ANONYMIZED');
            }
            if (! $fresh->updateIfVersion($version, ['name' => Customer::ANONYMIZED_NAME, 'phone' => null, 'email' => null, 'anonymized_at' => now()])) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            $customerId = new ObjectId($fresh->getKey());
            CustomerBranchProfile::query()->where('customer_id', $customerId)->update(['notes' => null, 'preferences' => [], 'updated_at' => now()]);
            $reservations = self::anonymizeReservations(['customer_id' => $customerId]);
            Audit::record('customer.anonymized', 'customer', $fresh->getKey(), $branchId, new ObjectId($actor->getKey()), reason: $reason,
                changes: ['reservations' => $reservations]);
            Outbox::publish('customer.anonymized', 'customer', $fresh->getKey(), $branchId, ['customerId' => $fresh->uuid]);
        });

        return $customer->refresh();
    }

    /**
     * Borra los datos personales de las reservas que cumplen el filtro: nombre, teléfono y notas. La usan
     * la anonimización de un cliente y la retención de 12 meses. Devuelve cuántas cambió.
     */
    public static function anonymizeReservations(array $filter): int
    {
        $query = DB::connection('mongodb')->table('reservations')->whereNull('personal_data_removed_at');
        foreach ($filter as $field => $value) {
            is_array($value) ? $query->where($field, ...$value) : $query->where($field, $value);
        }

        return $query->update([
            'customer_snapshot' => ['name' => Customer::ANONYMIZED_NAME, 'phone' => null],
            'notes' => null,
            'personal_data_removed_at' => new UTCDateTime(now()),
        ]);
    }

    /** Lo justo para saber qué cambió sin guardar el dato: inicial del nombre, últimos 4 del teléfono, dominio del correo. */
    private static function masked(array $in): array
    {
        $out = [];
        foreach ($in as $field => $value) {
            $out[$field] = match (true) {
                $value === null => null,
                $field === 'name' => mb_substr((string) $value, 0, 1).'…',
                $field === 'phone' => '…'.substr((string) $value, -4),
                $field === 'email' => '…@'.substr(strrchr((string) $value, '@') ?: '', 1),
                $field === 'notes' => '(cambió)',
                $field === 'preferences' => count((array) $value).' preferencias',
                default => null,
            };
        }

        return $out;
    }
}
