<?php

namespace Tests\Feature\Floor;

use App\Models\Branch;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Identity\IdentityTestCase;

/** Sucursal con los datos de restia:demo y un mesero, un cocinero y un gerente con sesión Android. */
abstract class FloorTestCase extends IdentityTestCase
{
    protected Branch $branch;

    /** @var array<string, array{user: User, access: string}> */
    protected array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = $this->branch('CENTRO');
        $this->artisan('restia:demo', ['--branch' => 'CENTRO'])->assertSuccessful();
        $this->branch->refresh();
        foreach (['mesero' => 'WAITER', 'cocina' => 'KITCHEN', 'gerente' => 'MANAGER'] as $name => $role) {
            $this->staff[$name] = $this->staffMember("$name@ejemplo.mx", $this->branch, $role);
        }
    }

    /** @return array{user: User, access: string} */
    protected function staffMember(string $email, Branch $branch, string $role): array
    {
        $user = $this->user($email, $branch, $role);

        return ['user' => $user, 'access' => $this->tokens($user, $this->enrolledDevice($branch))['access']];
    }

    protected function as(string $who, string $method, string $path, array $body = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $uri = str_starts_with($path, '/') ? '/api/v1'.$path : '/api/v1/branches/'.$this->branch->uuid.'/'.$path;

        return $this->json($method, $uri, $body, ['Authorization' => 'Bearer '.$this->staff[$who]['access']] + $headers);
    }

    protected function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    protected function table(int $number): RestaurantTable
    {
        return RestaurantTable::query()->where('number', $number)->firstOrFail();
    }

    protected function product(string $code): string
    {
        return Product::query()->where('code', $code)->firstOrFail()->uuid;
    }

    /** @return array{modifierId: string, optionId: string} */
    protected function option(string $modifierCode, string $optionName): array
    {
        $m = Modifier::query()->where('code', $modifierCode)->firstOrFail();

        return ['modifierId' => $m->uuid, 'optionId' => collect($m->options)->firstWhere('name', $optionName)['uuid']];
    }

    protected function openSession(int $table = 1, string $who = 'mesero'): string
    {
        return $this->as($who, 'POST', 'table-sessions', ['tableIds' => [$this->table($table)->uuid], 'guestCount' => 2], $this->key())
            ->assertCreated()->json('data.id');
    }

    /** Dos aguas grandes y unos tacos con queso extra: 2 × 50 + 107 = 207.00. */
    protected function round(string $session, ?string $client = null): array
    {
        return [
            'sessionId' => $session,
            'clientOrderUuid' => $client ?? (string) Str::uuid(),
            'items' => [
                ['productId' => $this->product('AGUA'), 'quantity' => 2, 'modifierOptions' => [$this->option('TAMANO', 'Grande')], 'notes' => 'Sin hielo'],
                ['productId' => $this->product('TACOS'), 'quantity' => 1, 'modifierOptions' => [$this->option('EXTRAS', 'Queso extra')]],
            ],
        ];
    }

    /** @return list<array{id: string, version: int}> */
    protected function unitsOf(array $order, int $item = 0, ?int $take = null): array
    {
        $units = array_map(fn ($u) => ['id' => $u['id'], 'version' => $u['version']], $order['items'][$item]['units']);

        return $take === null ? $units : array_slice($units, 0, $take);
    }

    protected function transition(string $who, string $action, array $units, ?string $reason = null, array $headers = []): TestResponse
    {
        $body = ['action' => $action, 'units' => $units] + ($reason === null ? [] : ['reason' => $reason]);

        return $this->as($who, 'POST', 'order-units/transitions', $body, $this->key() + $headers);
    }

    /** Turno de caja abierto por el gerente, para cobrar en las pruebas. */
    protected function cashShift(): string
    {
        $open = $this->as('gerente', 'GET', 'cash-shifts?status=OPEN')->json('data.0.id');
        if ($open) {
            return $open;
        }
        $register = $this->as('gerente', 'POST', 'cash-registers', ['code' => 'CAJA-PRUEBA', 'name' => 'Caja de pruebas'])->assertCreated()->json('data.id');

        return $this->as('gerente', 'POST', 'cash-shifts', ['cashRegisterId' => $register, 'openingAmount' => ['amount' => '0', 'currency' => 'MXN']], $this->key())
            ->assertCreated()->json('data.id');
    }

    /** Cobra en efectivo el saldo de cada cuenta pendiente de la sesión. */
    protected function payAll(string $session): void
    {
        $shift = $this->cashShift();
        foreach ($this->as('mesero', 'GET', "checks?sessionId=$session")->json('data') as $check) {
            if (in_array($check['status'], ['OPEN', 'REQUESTED', 'PARTIALLY_PAID'], true) && $check['balance']['amount'] !== '0.00') {
                $amount = $check['balance'];
                $this->as('mesero', 'POST', 'payments', [
                    'checkId' => $check['id'], 'cashShiftId' => $shift, 'method' => 'CASH', 'amount' => $amount, 'amountTendered' => $amount,
                ], $this->key())->assertCreated();
            }
        }
    }

    /** @param list<array{id: string, version: int}> $units */
    protected function bump(array $units): array
    {
        return array_map(fn ($u) => ['id' => $u['id'], 'version' => $u['version'] + 1], $units);
    }
}
