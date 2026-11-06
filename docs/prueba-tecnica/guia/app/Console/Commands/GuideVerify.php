<?php

namespace App\Console\Commands;

use App\Actions\OpenTableSession;
use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\RestaurantTable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\Process\Process;

class GuideVerify extends Command
{
    protected $signature = 'guide:verify';

    private function check(string $name, bool $ok, $extra = ''): void
    {
        $this->line(($ok ? 'PASA ' : 'FALLA ').$name.' '.(is_string($extra) ? $extra : json_encode($extra)));
    }

    public function handle(): int
    {
        $db = DB::connection('mongodb');
        foreach (['branches', 'table_sessions', 'audit_log', 'outbox_events', 'payments'] as $c) {
            $db->getCollection($c)->drop();
            $db->getDatabase()->createCollection($c);
        }
        foreach (['tables', 'table_assignments', 'idempotency_records'] as $c) {
            $db->getCollection($c)->deleteMany([]);
        }

        $idx = [];
        foreach ($db->getCollection('table_assignments')->listIndexes() as $i) {
            $idx[$i->getName()] = ['unique' => $i->isUnique(), 'partial' => $i->__debugInfo()['partialFilterExpression'] ?? ($i['partialFilterExpression'] ?? null)];
        }
        $this->check('indice parcial creado', isset($idx['una_asignacion_activa_por_mesa']) && $idx['una_asignacion_activa_por_mesa']['unique']);
        $ttl = false;
        foreach ($db->getCollection('idempotency_records')->listIndexes() as $i) {
            $ttl = $ttl || $i->isTtl();
        }
        $this->check('indice TTL creado', $ttl);

        $branch = Branch::create(['status' => 'ACTIVE', 'code' => 'B1', 'operations_epoch' => 0]);
        $this->check('uuid y version automaticos', strlen($branch->uuid) === 36 && $branch->version === 1);
        $this->check('updateIfVersion correcto', $branch->updateIfVersion(1, ['name' => 'x']) && $branch->version === 2 && $branch->name === 'x');
        $this->check('updateIfVersion rechaza version vieja', ! $branch->updateIfVersion(1, ['name' => 'y']) && $branch->fresh()->name === 'x');

        $p = Payment::create(['branch_id' => $branch->getKey(), 'amount' => '10.50', 'tip' => '0.00', 'confirmed_at' => now()]);
        $raw = $db->getCollection('payments')->findOne(['uuid' => $p->uuid]);
        $this->check('dinero guardado como Decimal128', $raw['amount'] instanceof Decimal128, (string) $raw['amount']);
        $this->check('dinero sale como string con escala', Payment::where('uuid', $p->uuid)->first()->toArray()['amount'] === '10.50');
        $this->check('fecha guardada como UTCDateTime', $raw['confirmed_at'] instanceof UTCDateTime && $raw['created_at'] instanceof UTCDateTime);

        for ($i = 0; $i < 20; $i++) {
            RestaurantTable::create(['branch_id' => $branch->getKey(), 'number' => $i + 1, 'status' => 'ACTIVE', 'qr_token' => 'q'.$i, 'uuid' => "mesa-$i"]);
        }
        $tablesJson = RestaurantTable::where('uuid', 'mesa-0')->first()->toArray();
        $this->check('secretos ocultos al serializar', ! isset($tablesJson['qr_token']) && ! isset($tablesJson['_id']));

        $action = new OpenTableSession;
        $key = '11111111-1111-4111-8111-111111111111';
        $u1 = str_repeat('a', 24);
        $u2 = str_repeat('b', 24);
        $first = $action->run($branch->uuid, ['mesa-0'], 2, $u1, $key);
        $second = $action->run($branch->uuid, ['mesa-0'], 2, $u1, $key);
        $assignment = $db->getCollection('table_assignments')->findOne(['active' => true]);
        $this->check('referencias guardadas como ObjectId', $assignment['branch_id'] instanceof \MongoDB\BSON\ObjectId && $assignment['table_id'] instanceof \MongoDB\BSON\ObjectId && $assignment['session_id'] instanceof \MongoDB\BSON\ObjectId);
        $this->check('forBranch encuentra por ObjectId', RestaurantTable::forBranch($branch)->count() === 20);
        $this->check('un string no encuentra nada', RestaurantTable::where('branch_id', $branch->getKey())->count() === 0, RestaurantTable::where('branch_id', $branch->getKey())->count());
        $this->check('misma clave devuelve el resultado original', $first === $second, $first['id']);
        foreach ([[['mesa-0'], 3, $u1, 'IDEMPOTENCY_KEY_REUSED', $key], [['mesa-0'], 2, $u2, 'IDEMPOTENCY_KEY_REUSED', $key], [['mesa-0'], 2, $u1, 'TABLE_ALREADY_ASSIGNED', '22222222-2222-4222-8222-222222222222']] as [$t, $g, $u, $code, $k]) {
            try {
                $action->run($branch->uuid, $t, $g, $u, $k);
                $this->check("espera $code", false);
            } catch (DomainConflict $e) {
                $this->check("espera $code", $e->errorCode === $code, $e->errorCode);
            }
        }
        $this->check('un fallo no deja rastro', $db->table('idempotency_records')->where('key', '22222222-2222-4222-8222-222222222222')->count() === 0
            && $db->table('table_sessions')->count() === 1 && $db->table('audit_log')->count() === 1 && $db->table('outbox_events')->count() === 1);

        $db->getCollection('table_assignments')->deleteMany([]);
        $db->getCollection('idempotency_records')->deleteMany([]);
        $db->getCollection('table_sessions')->deleteMany([]);
        $start = microtime(true) + 4;
        $ps = [];
        for ($i = 0; $i < 6; $i++) {
            $x = new Process([PHP_BINARY, base_path('artisan'), 'guide:worker', $branch->uuid, 'mesa', "--start=$start", '--id='.$i], base_path(), null, null, 200);
            $x->start();
            $ps[] = $x;
        }
        $sum = ['ok' => 0, 'assigned' => 0, 'busy' => 0, 'other' => []];
        foreach ($ps as $x) {
            $x->wait();
            $lines = explode("\n", trim($x->getOutput()));
            $j = json_decode(end($lines), true) ?? ['other' => [substr($x->getOutput().$x->getErrorOutput(), -200) => 1], 'ok' => 0, 'assigned' => 0, 'busy' => 0];
            $sum['ok'] += $j['ok'];
            $sum['assigned'] += $j['assigned'];
            $sum['busy'] += $j['busy'];
            $sum['other'] += $j['other'];
        }
        $dup = iterator_to_array($db->getCollection('table_assignments')->aggregate([['$match' => ['active' => true]], ['$group' => ['_id' => '$table_id', 'n' => ['$sum' => 1]]], ['$match' => ['n' => ['$gt' => 1]]]]));
        $this->check('6 procesos sobre 20 mesas: un ganador por mesa', count($dup) === 0 && $sum['ok'] === 20 && $sum['other'] === [], $sum);
        $this->check('sesiones sin huerfanas', $db->table('table_sessions')->count() === 20, $db->table('table_sessions')->count());

        return 0;
    }
}
