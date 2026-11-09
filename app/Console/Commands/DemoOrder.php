<?php

namespace App\Console\Commands;

use App\Floor\Orders;
use App\Floor\TableSessions;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductPreparationRoute;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

/**
 * Manda una ronda de comanda de prueba sin la app: abre una mesa libre (o usa la indicada si ya tiene
 * sesión) y pide productos vendibles de cada estación. Pasa por las mismas reglas que la API.
 * No corre en producción.
 */
class DemoOrder extends Command
{
    protected $signature = 'restia:demo-order
        {--branch= : Código de la sucursal; por omisión, la única que exista}
        {--table= : Número de mesa; por omisión, la primera libre}';

    protected $description = 'Abre una mesa y manda una ronda de comanda de prueba';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('restia:demo-order no corre en producción.');

            return self::FAILURE;
        }
        $branch = self::branch($this->option('branch'));
        $actor = $branch ? $this->staff($branch) : null;
        if (! $actor) {
            $this->error('Hace falta una sucursal con empleados; ejecuta antes restia:install y restia:demo.');

            return self::FAILURE;
        }
        $branchId = new ObjectId($branch->getKey());
        $session = $this->session($branch, $branchId, $actor);
        if (! $session) {
            return self::FAILURE;
        }
        $items = $this->items($branchId);
        if (! $items) {
            $this->error('No hay productos activos y disponibles; ejecuta antes restia:demo.');

            return self::FAILURE;
        }
        [, $order] = Orders::create($branch, $actor, (string) Str::uuid(), [
            'sessionId' => $session->uuid, 'clientOrderUuid' => (string) Str::uuid(), 'items' => $items,
        ]);

        $this->info("Comanda {$order['id']} en la sesión {$session->uuid}:");
        foreach ($order['items'] as $item) {
            $this->line("  {$item['quantity']} x {$item['name']}");
        }
        $this->line('Si el scheduler corre (php artisan schedule:work), en unos segundos sale en el agente.');

        return self::SUCCESS;
    }

    public static function branch(?string $code): ?Branch
    {
        return $code ? Branch::query()->where('code', $code)->first()
            : (Branch::query()->count() === 1 ? Branch::query()->first() : null);
    }

    /** Un mesero, si hay; si no, cualquiera que pueda abrir mesas y comandar. */
    private function staff(Branch $branch): ?User
    {
        $memberships = Membership::query()->where('branch_id', new ObjectId($branch->getKey()))->where('status', Membership::ACTIVE)->get()
            ->filter(fn (Membership $m) => $m->can('orders.create') && $m->can('table_sessions.open'))
            ->sortBy(fn (Membership $m) => $m->role === 'WAITER' ? 0 : 1);
        foreach ($memberships as $m) {
            $user = User::query()->where('_id', new ObjectId((string) $m->user_id))->where('status', User::ACTIVE)->first();
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    private function session(Branch $branch, ObjectId $branchId, User $actor): ?TableSession
    {
        $busy = TableAssignment::query()->where('branch_id', $branchId)->where('active', true)->get()->map(fn ($a) => (string) $a->table_id)->all();
        $tables = RestaurantTable::query()->where('branch_id', $branchId)->where('status', RestaurantTable::ACTIVE)->orderBy('number')->get();
        $wanted = $this->option('table');
        if ($wanted !== null) {
            $table = $tables->firstWhere('number', (int) $wanted);
            if (! $table) {
                $this->error("La mesa $wanted no existe o no está activa.");

                return null;
            }
            if (in_array((string) $table->getKey(), $busy, true)) {
                $assignment = TableAssignment::query()->where('branch_id', $branchId)->where('active', true)->where('table_id', new ObjectId($table->getKey()))->first();
                $session = TableSession::query()->find((string) $assignment->session_id);
                if ($session?->status !== TableSession::ACTIVE) {
                    $this->error("La mesa $wanted está ocupada por una sesión que no admite comandas.");

                    return null;
                }

                return $session;
            }
        } else {
            $table = $tables->first(fn ($t) => ! in_array((string) $t->getKey(), $busy, true));
            if (! $table) {
                $this->error('No hay mesas libres; indica una ocupada con --table.');

                return null;
            }
        }
        [, $data] = TableSessions::open($branch, $actor, (string) Str::uuid(), ['tableIds' => [$table->uuid], 'guestCount' => 2]);
        $this->line("Mesa {$table->number} abierta.");

        return TableSession::query()->where('uuid', $data['id'])->first();
    }

    /**
     * Un producto vendible de cada estación, con las opciones mínimas que pide cada modificador.
     *
     * @return list<array<string, mixed>>
     */
    private function items(ObjectId $branchId): array
    {
        $products = Product::query()->where('branch_id', $branchId)->where('status', Product::ACTIVE)->where('availability', 'AVAILABLE')
            ->orderBy('category')->orderBy('display_order')->get();
        $routes = ProductPreparationRoute::query()->where('branch_id', $branchId)->orderBy('priority')->get()
            ->groupBy(fn ($r) => (string) $r->product_id);
        $modifiers = Modifier::query()->where('branch_id', $branchId)->get()->keyBy(fn ($m) => (string) $m->getKey());
        $items = [];
        $seen = [];
        foreach ($products as $p) {
            $station = (string) ($routes->get((string) $p->getKey())?->first()?->station_id ?? '');
            if ($station === '' || isset($seen[$station])) {
                continue;
            }
            $seen[$station] = true;
            $options = [];
            foreach ($p->modifier_ids ?? [] as $id) {
                $m = $modifiers->get((string) $id);
                $active = collect($m?->options ?? [])->where('status', 'ACTIVE')->take((int) ($m?->min_selections ?? 0));
                foreach ($active as $o) {
                    $options[] = ['modifierId' => $m->uuid, 'optionId' => $o['uuid']];
                }
            }
            $items[] = ['productId' => $p->uuid, 'quantity' => count($items) + 1, 'modifierOptions' => $options];
        }

        return $items;
    }
}
