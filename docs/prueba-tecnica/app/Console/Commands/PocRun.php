<?php

namespace App\Console\Commands;

use App\Models\PocToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MongoDB\BSON\Decimal128;
use Symfony\Component\Process\Process;

/** Prueba técnica de Restia: una función por prueba del plan de implementación. */
class PocRun extends Command
{
    protected $signature = 'poc:run {tests=1,2,3,4,5,6,7,8,9} {--base=http://127.0.0.1:8123}';
    protected $description = 'Ejecuta la prueba técnica';
    private array $results = [];

    public function handle(): int
    {
        foreach (explode(',', $this->argument('tests')) as $n) {
            $n = (int) $n;
            try {
                [$pass, $detail] = $this->{"t$n"}();
            } catch (\Throwable $e) {
                [$pass, $detail] = [false, ['excepcion' => get_class($e), 'mensaje' => substr($e->getMessage(), 0, 400), 'en' => basename($e->getFile()).':'.$e->getLine()]];
            }
            $this->results[$n] = ['pass' => $pass, 'detail' => $detail];
            $this->line("T$n ".($pass ? 'PASA' : 'FALLA').' '.json_encode($detail, JSON_UNESCAPED_UNICODE));
        }
        file_put_contents(storage_path('poc_results_'.date('His').'.json'), json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 0;
    }

    private function http(string $method, string $path, array $headers = [], ?array $body = null, ?string $jar = null): array
    {
        $c = curl_init($this->option('base').$path);
        $h = array_merge(['Accept: application/json'], $headers);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20]);
        if ($body !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($body));
            $h[] = 'Content-Type: application/json';
        }
        if ($jar) {
            curl_setopt_array($c, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
        }
        curl_setopt($c, CURLOPT_HTTPHEADER, $h);
        $r = curl_exec($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        if ($r === false) {
            $r = 'curl: '.curl_error($c);
        }
        curl_close($c);

        return [$code, $r];
    }

    private function user(string $email = 'poc@restia.test'): User
    {
        User::where('email', $email)->delete();

        return User::create(['email' => $email, 'name' => 'Poc', 'password' => Hash::make('secreto-poc-123'), 'status' => 'ACTIVE']);
    }

    /** 1. Sanctum + laravel-mongodb con PersonalAccessToken propio. */
    private function t1(): array
    {
        DB::connection('mongodb')->getCollection('personal_access_tokens')->drop();
        $u = $this->user();
        $new = $u->createToken('android', ['*'], now()->addMinutes(30));
        $plain = $new->plainTextToken;
        $stored = DB::connection('mongodb')->getCollection('personal_access_tokens')->findOne();
        [$ok] = $this->http('GET', '/api/me', ["Authorization: Bearer $plain"]);
        [$bad] = $this->http('GET', '/api/me', ['Authorization: Bearer x|nope']);
        [$none] = $this->http('GET', '/api/me');
        $id = $new->accessToken->getKey();
        $used = PocToken::find($id)->last_used_at;
        // Vencido
        $exp = $u->createToken('vencido', ['*'], now()->subMinute())->plainTextToken;
        [$expired] = $this->http('GET', '/api/me', ["Authorization: Bearer $exp"]);
        // Revocado
        PocToken::find($id)->delete();
        [$revoked] = $this->http('GET', '/api/me', ["Authorization: Bearer $plain"]);
        Artisan::call('sanctum:prune-expired', ['--hours' => 0]);
        $left = PocToken::count();
        $d = ['valido' => $ok, 'invalido' => $bad, 'sin_token' => $none, 'vencido' => $expired, 'revocado' => $revoked, 'last_used_at' => (bool) $used,
            'hash_guardado' => strlen((string) $stored['token']) === 64, 'tras_prune' => $left, 'id_tipo' => gettype($id)];

        return [$ok === 200 && $bad === 401 && $none === 401 && $expired === 401 && $revoked === 401 && $used && $left === 0, $d];
    }

    /** 2. Sesión Web en MongoDB, con el flujo CSRF de Sanctum. */
    private function t2(): array
    {
        $this->user('web@restia.test');
        $db = DB::connection('mongodb');
        $db->getCollection('sessions')->drop();
        $jar = storage_path('poc_cookies.txt');
        @unlink($jar);
        $o = ['Origin: '.$this->option('base'), 'Referer: '.$this->option('base').'/'];
        [$csrf] = $this->http('GET', '/sanctum/csrf-cookie', $o, null, $jar);
        $xsrf = null;
        foreach (file($jar) as $l) {
            if (str_contains($l, 'XSRF-TOKEN')) {
                $xsrf = urldecode(trim(substr($l, strrpos($l, "\t") + 1)));
            }
        }
        [$noCsrf] = $this->http('POST', '/api/login', $o, ['email' => 'web@restia.test', 'password' => 'secreto-poc-123'], $jar);
        $h = array_merge($o, ["X-XSRF-TOKEN: $xsrf"]);
        [$wrong] = $this->http('POST', '/api/login', $h, ['email' => 'web@restia.test', 'password' => 'mala'], $jar);
        [$login, $lb] = $this->http('POST', '/api/login', $h, ['email' => 'web@restia.test', 'password' => 'secreto-poc-123'], $jar);
        foreach (file($jar) as $l) {
            if (str_contains($l, 'XSRF-TOKEN')) {
                $xsrf = urldecode(trim(substr($l, strrpos($l, "\t") + 1)));
            }
        }
        [$me] = $this->http('GET', '/api/me', $o, null, $jar);
        $docs = $db->getCollection('sessions')->countDocuments();
        $withUser = $db->getCollection('sessions')->countDocuments(['user_id' => ['$exists' => true]]);
        [$logout] = $this->http('POST', '/api/logout', array_merge($o, ["X-XSRF-TOKEN: $xsrf"]), [], $jar);
        [$after] = $this->http('GET', '/api/me', $o, null, $jar);
        $d = ['csrf_cookie' => $csrf, 'login_sin_csrf' => $noCsrf, 'clave_mala' => $wrong, 'login' => $login, 'me' => $me, 'docs_sesion' => $docs, 'con_usuario' => $withUser, 'logout' => $logout, 'me_tras_logout' => $after];
        if ($login !== 200) {
            $d['cuerpo'] = substr($lb, 0, 300);
        }

        return [$csrf === 204 && $noCsrf === 419 && $wrong === 422 && $login === 200 && $me === 200 && $docs >= 1 && $after === 401, $d];
    }

    /** 3. Costo de last_used_at. */
    private function t3(): array
    {
        $u = $this->user('t3@restia.test');
        $t = $u->createToken('x')->plainTextToken;
        $this->http('GET', '/api/ping-auth', ["Authorization: Bearer $t"]);
        $run = function (array $h) {
            $l = [];
            for ($i = 0; $i < 200; $i++) {
                $s = microtime(true);
                [$c] = $this->http('GET', '/api/ping-auth', $h);
                if ($c !== 200) {
                    throw new \RuntimeException("status $c");
                }
                $l[] = (microtime(true) - $s) * 1000;
            }
            sort($l);

            return ['media_ms' => round(array_sum($l) / count($l), 1), 'p95_ms' => round($l[190], 1)];
        };
        $base = ["Authorization: Bearer $t"];
        $run($base); // calentamiento
        $with1 = $run($base);
        $without1 = $run(array_merge($base, ['X-Poc-Skip: 1']));
        $with2 = $run($base);
        $without2 = $run(array_merge($base, ['X-Poc-Skip: 1']));
        $w = ($with1['media_ms'] + $with2['media_ms']) / 2;
        $wo = ($without1['media_ms'] + $without2['media_ms']) / 2;
        $d = ['con_escritura' => [$with1, $with2], 'sin_escritura' => [$without1, $without2], 'costo_ms' => round($w - $wo, 1)];

        return [($w - $wo) < 25, $d];
    }

    private function spawn(string $scenario, int $n, array $opts = [], float $delay = 3.0): array
    {
        $start = microtime(true) + $delay;
        $ps = [];
        for ($i = 0; $i < $n; $i++) {
            $args = [PHP_BINARY, base_path('artisan'), 'poc:worker', $scenario, "--id=$i", "--start=$start"];
            foreach ($opts as $k => $v) {
                $args[] = "--$k=$v";
            }
            $p = new Process($args, base_path(), null, null, 300);
            $p->start();
            $ps[] = $p;
        }
        $out = [];
        foreach ($ps as $p) {
            $p->wait();
            $line = trim($p->getOutput());
            $j = json_decode(substr($line, strrpos($line, "\n") === false ? 0 : strrpos($line, "\n") + 1), true);
            if ($j === null) {
                throw new \RuntimeException('worker: '.substr($line.$p->getErrorOutput(), -500));
            }
            $out[] = $j;
        }

        return $out;
    }

    private function sum(array $rs, string $k): int
    {
        return array_sum(array_column($rs, $k));
    }

    private function msgs(array $rs): array
    {
        return array_merge(...array_map(fn ($r) => $r['msgs'] ?? [], $rs));
    }

    private function bookArm(string $scenario, int $workers, int $rounds): array
    {
        $db = DB::connection('mongodb');
        foreach (['poc_bookings', 'poc_branches'] as $c) {
            $db->getCollection($c)->drop();
            $db->getDatabase()->createCollection($c);
        }
        $db->table('poc_branches')->insert(['code' => 'B1', 'operations_epoch' => 0]);
        $rs = $this->spawn($scenario, $workers, ['rounds' => $rounds, 'step' => 80, 'attempts' => 20]);
        $slots = iterator_to_array($db->getCollection('poc_bookings')->aggregate([['$group' => ['_id' => '$slot', 'n' => ['$sum' => 1]]], ['$match' => ['n' => ['$gt' => 1]]]]));
        $total = $db->getCollection('poc_bookings')->countDocuments();

        return ['rondas' => $rounds, 'procesos' => $workers, 'dobles_apartados' => count($slots), 'reservas' => $total, 'intentos' => $this->sum($rs, 'tries'),
            'exitos_vistos' => $this->sum($rs, 'ok'), 'ocupado_visto' => $this->sum($rs, 'taken'), 'errores' => $this->sum($rs, 'errors'), 'mensajes' => $this->msgs($rs)];
    }

    /** 4. findOneAndUpdate en transacción con reintentos. */
    private function t4(): array
    {
        $db = DB::connection('mongodb');
        foreach (['poc_sessions', 'poc_rotations'] as $c) {
            $db->getCollection($c)->drop();
            $db->getDatabase()->createCollection($c);
        }
        $rounds = 30;
        for ($i = 0; $i < $rounds; $i++) {
            $db->table('poc_sessions')->insert(['round' => $i, 'hash' => 'old']);
        }
        $rs = $this->spawn('rotate', 8, ['rounds' => $rounds, 'step' => 80, 'attempts' => 20]);
        $per = iterator_to_array($db->getCollection('poc_rotations')->aggregate([['$group' => ['_id' => '$round', 'n' => ['$sum' => 1]]]]));
        $counts = array_map(fn ($x) => $x['n'], $per);
        $d = ['rondas' => $rounds, 'procesos' => 8, 'rondas_con_un_ganador' => count(array_filter($counts, fn ($n) => $n === 1)), 'rondas_con_mas_de_uno' => count(array_filter($counts, fn ($n) => $n > 1)),
            'intentos' => $this->sum($rs, 'tries'), 'esperados_sin_reintento' => 8 * $rounds, 'errores' => $this->sum($rs, 'errors'), 'mensajes' => $this->msgs($rs)];

        return [count($counts) === $rounds && $d['rondas_con_mas_de_uno'] === 0 && $d['errores'] === 0, $d];
    }

    /** 5. Control sin epoch, y patrón con epoch. */
    private function t5(): array
    {
        $control = $this->bookArm('book-noepoch', 6, 40);
        $epoch = $this->bookArm('book-epoch', 6, 40);
        $d = ['control_sin_epoch' => $control, 'con_epoch' => $epoch];

        return [$control['dobles_apartados'] > 0 && $epoch['dobles_apartados'] === 0 && $epoch['reservas'] === 40 && $epoch['errores'] === 0, $d];
    }

    /** 6. Índice único parcial en transacción. */
    private function t6(): array
    {
        $db = DB::connection('mongodb');
        foreach (['poc_assign', 'poc_assign_log'] as $c) {
            $db->getCollection($c)->drop();
            $db->getDatabase()->createCollection($c);
        }
        $db->getCollection('poc_assign')->createIndex(['table' => 1], ['unique' => true, 'partialFilterExpression' => ['active' => true]]);
        $rounds = 30;
        $rs = $this->spawn('partial', 6, ['rounds' => $rounds, 'step' => 80, 'attempts' => (int) env('POC_T6_ATTEMPTS', 5)]);
        $active = $db->getCollection('poc_assign')->countDocuments(['active' => true]);
        $logs = $db->getCollection('poc_assign_log')->countDocuments();
        // Liberar permite volver a asignar
        $db->table('poc_assign')->where('table', 0)->update(['active' => false]);
        $db->table('poc_assign')->insert(['table' => 0, 'active' => true, 'by' => 99]);
        $hist = $db->getCollection('poc_assign')->countDocuments(['table' => 0]);
        // Rechazo fuera de hora: un duplicado revierte también lo demás de la transacción
        $rolled = false;
        try {
            $db->transaction(function () use ($db) {
                $db->table('poc_assign_log')->insert(['table' => 'rollback']);
                $db->table('poc_assign')->insert(['table' => 1, 'active' => true, 'by' => 100]);
            });
        } catch (\Throwable $e) {
            $rolled = $db->getCollection('poc_assign_log')->countDocuments(['table' => 'rollback']) === 0;
        }
        $d = ['rondas' => $rounds, 'activas' => $active, 'confirmadas' => $this->sum($rs, 'committed'), 'duplicados' => $this->sum($rs, 'dup'), 'otros_errores' => $this->sum($rs, 'other'),
            'bitacora_coincide' => $logs === $rounds, 'reasignar_tras_liberar' => $hist === 2, 'duplicado_revierte_todo' => $rolled, 'mensajes' => $this->msgs($rs)];

        return [$active === $rounds && $this->sum($rs, 'committed') === $rounds && $logs === $rounds && $hist === 2 && $rolled, $d];
    }

    /** 7. Contención de operations_epoch. */
    private function t7(): array
    {
        $db = DB::connection('mongodb');
        $levels = [];
        foreach ([1, 2, 4, 8, 16] as $k) {
            foreach (['poc_branches', 'poc_load', 'poc_bookings'] as $c) {
                $db->getCollection($c)->drop();
                $db->getDatabase()->createCollection($c);
            }
            $db->table('poc_branches')->insert(['code' => 'B1', 'operations_epoch' => 0]);
            $sec = 8;
            $rs = $this->spawn('load', $k, ['seconds' => $sec, 'attempts' => 5], 2.0 + $k * 0.25);
            $lat = array_merge(...array_map(fn ($r) => $r['lat'], $rs));
            sort($lat);
            $ops = count($lat);
            $tries = $this->sum($rs, 'tries');
            $levels[] = ['procesos' => $k, 'ops_por_seg' => round($ops / $sec, 1), 'p50_ms' => $ops ? round($lat[(int) ($ops * .5)], 1) : null, 'p95_ms' => $ops ? round($lat[(int) ($ops * .95)], 1) : null,
                'p99_ms' => $ops ? round($lat[(int) ($ops * .99)], 1) : null, 'reintentos_por_op' => $ops ? round($tries / $ops - 1, 2) : null, 'agotados' => $this->sum($rs, 'busy'),
                'pct_agotados' => round(100 * $this->sum($rs, 'busy') / max(1, $ops + $this->sum($rs, 'busy')), 2), 'mensajes' => array_slice($this->msgs($rs), 0, 2)];
        }
        $m = end($levels);

        return [$levels[2]['p95_ms'] !== null && $levels[2]['pct_agotados'] < 1, ['niveles' => $levels]];
    }

    /** 7b. Contención a tasas de operación, no a saturación. */
    private function t10(): array
    {
        $db = DB::connection('mongodb');
        $levels = [];
        foreach ([[2, 5, 0], [10, 5, 0], [50, 5, 0], [100, 5, 0], [200, 5, 0], [50, 5, 1], [100, 5, 1], [200, 5, 1], [200, 10, 1], [400, 10, 1]] as [$total, $att, $bo]) {
            foreach (['poc_branches', 'poc_load', 'poc_bookings'] as $c) {
                $db->getCollection($c)->drop();
                $db->getDatabase()->createCollection($c);
            }
            $db->table('poc_branches')->insert(['code' => 'B1', 'operations_epoch' => 0]);
            $k = 8;
            $sec = $total < 10 ? 20 : 10;
            $rs = $this->spawn('load', $k, ['seconds' => $sec, 'attempts' => $att, 'rate' => $total / $k, 'backoff' => $bo], 4.0);
            $lat = array_merge(...array_map(fn ($r) => $r['lat'], $rs));
            sort($lat);
            $ops = count($lat);
            $busy = $this->sum($rs, 'busy');
            $levels[] = ['objetivo_ops_seg' => $total, 'intentos' => $att, 'espera' => (bool) $bo, 'ops_seg' => round($ops / $sec, 1), 'p50_ms' => round($lat[(int) ($ops * .5)], 1), 'p95_ms' => round($lat[(int) ($ops * .95)], 1), 'p99_ms' => round($lat[(int) ($ops * .99)], 1),
                'reintentos_por_op' => round($this->sum($rs, 'tries') / max(1, $ops) - 1, 2), 'agotados' => $busy, 'pct_agotados' => round(100 * $busy / max(1, $ops + $busy), 2)];
        }

        return [true, ['niveles' => $levels]];
    }

    /** 8. Decimal128 conserva la escala. */
    private function t8(): array
    {
        $db = DB::connection('mongodb');
        $db->getCollection('poc_money')->drop();
        $c = $db->getCollection('poc_money');
        $vals = ['10.50', '0.10', '0.20', '1234567890.1234', '100.00'];
        foreach ($vals as $v) {
            $c->insertOne(['amount' => new Decimal128($v), 'raw' => $v]);
        }
        $roundTrip = [];
        foreach ($c->find() as $doc) {
            $roundTrip[$doc['raw']] = (string) $doc['amount'];
        }
        $sum = iterator_to_array($c->aggregate([['$match' => ['raw' => ['$in' => ['0.10', '0.20']]]], ['$group' => ['_id' => null, 's' => ['$sum' => '$amount']]]]))[0]['s'];
        // Por Eloquent, con el cast de la aplicación
        $m = \App\Models\PocMoney::create(['amount' => '10.50']);
        $stored = $c->findOne(['_id' => new \MongoDB\BSON\ObjectId($m->getKey())]);
        $fresh = \App\Models\PocMoney::find($m->getKey());
        $json = json_decode($fresh->toJson(), true)['amount'] ?? null;
        $d = ['ida_y_vuelta' => $roundTrip, 'suma_0_10_0_20' => (string) $sum, 'tipo_guardado' => is_object($stored['amount']) ? get_class($stored['amount']) : gettype($stored['amount']),
            'eloquent_atributo' => (string) $fresh->amount, 'eloquent_json' => $json];
        $exact = true;
        foreach ($vals as $v) {
            $exact = $exact && $roundTrip[$v] === $v;
        }

        return [$exact && (string) $sum === '0.30' && $stored['amount'] instanceof Decimal128 && $json === '10.50', $d];
    }

    /** 9. Inyección de operadores por query. */
    private function t9(): array
    {
        User::query()->delete();
        $this->user('a@restia.test');
        $b = $this->user('b@restia.test');
        $b->status = 'INACTIVE';
        $b->save();
        [$unsafeC, $unsafe] = $this->http('GET', '/api/filter-unsafe?status[$ne]=NADA');
        [$normalC, $normal] = $this->http('GET', '/api/filter-unsafe?status=ACTIVE');
        [$safeC, $safe] = $this->http('GET', '/api/filter-safe?status[$ne]=NADA');
        [$safeOk, $safeOkB] = $this->http('GET', '/api/filter-safe?status=ACTIVE');
        [$jsonC] = $this->http('POST', '/api/filter-safe', [], ['status' => ['$ne' => 'NADA']]);
        $d = ['sin_validar_operador' => [$unsafeC, json_decode($unsafe, true)], 'sin_validar_normal' => [$normalC, json_decode($normal, true)], 'validado_operador' => $safeC, 'validado_normal' => [$safeOk, json_decode($safeOkB, true)], 'validado_cuerpo_json' => $jsonC];

        return [$safeC === 422 && $jsonC === 422 && $safeOk === 200, $d];
    }
}
