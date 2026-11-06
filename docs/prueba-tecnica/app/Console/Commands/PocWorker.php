<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use Throwable;

/** Un proceso concurrente de la prueba técnica. Imprime su resultado en JSON. */
class PocWorker extends Command
{
    protected $signature = 'poc:worker {scenario} {--id=0} {--start=0} {--rounds=20} {--step=60} {--seconds=5} {--attempts=5} {--rate=0} {--backoff=0}';
    protected $description = 'Proceso concurrente de la prueba técnica';

    public function handle(): int
    {
        $s = $this->argument('scenario');
        $r = match ($s) {
            'book-epoch' => $this->book(true),
            'book-noepoch' => $this->book(false),
            'partial' => $this->partial(),
            'rotate' => $this->rotate(),
            'load' => $this->load(),
        };
        $this->line(json_encode($r));

        return 0;
    }

    private function waitRound(int $i): void
    {
        $at = (float) $this->option('start') + $i * ((int) $this->option('step')) / 1000;
        if ($at > microtime(true)) {
            time_sleep_until($at);
        }
    }

    /** Patrón de reservas: leer disponibilidad e insertar. Solo el epoch evita el doble apartado. */
    private function book(bool $epoch): array
    {
        $db = DB::connection('mongodb');
        $out = ['ok' => 0, 'taken' => 0, 'tries' => 0, 'errors' => 0, 'msgs' => []];
        for ($i = 0; $i < (int) $this->option('rounds'); $i++) {
            $this->waitRound($i);
            try {
                $db->transaction(function () use ($db, $i, $epoch, &$out) {
                    $out['tries']++;
                    if ($epoch) {
                        $db->table('poc_branches')->where('code', 'B1')->increment('operations_epoch');
                    }
                    $count = $db->table('poc_bookings')->where('slot', $i)->count();
                    usleep(5000);
                    if ($count === 0) {
                        $db->table('poc_bookings')->insert(['slot' => $i, 'by' => (int) $this->option('id')]);
                        $out['ok']++;
                    } else {
                        $out['taken']++;
                    }
                }, (int) $this->option('attempts'));
            } catch (Throwable $e) {
                $out['errors']++;
                $out['msgs'][class_basename($e)] = substr($e->getMessage(), 0, 120);
            }
        }

        return $out;
    }

    /** Índice único parcial dentro de una transacción. */
    private function partial(): array
    {
        $db = DB::connection('mongodb');
        $out = ['committed' => 0, 'dup' => 0, 'other' => 0, 'msgs' => []];
        for ($i = 0; $i < (int) $this->option('rounds'); $i++) {
            $this->waitRound($i);
            try {
                $db->transaction(function () use ($db, $i) {
                    $db->table('poc_assign')->insert(['table' => $i, 'active' => true, 'by' => (int) $this->option('id')]);
                    usleep(5000);
                    $db->table('poc_assign_log')->insert(['table' => $i, 'by' => (int) $this->option('id')]);
                }, (int) $this->option('attempts'));
                $out['committed']++;
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'E11000')) {
                    $out['dup']++;
                } else {
                    $out['other']++;
                    $out['msgs'][class_basename($e)] = substr($e->getMessage(), 0, 120);
                }
            }
        }

        return $out;
    }

    /** Rotación de un refresh token con findOneAndUpdate dentro de una transacción. */
    private function rotate(): array
    {
        $db = DB::connection('mongodb');
        $out = ['won' => 0, 'lost' => 0, 'tries' => 0, 'errors' => 0, 'msgs' => []];
        for ($i = 0; $i < (int) $this->option('rounds'); $i++) {
            $this->waitRound($i);
            try {
                $db->transaction(function () use ($db, $i, &$out) {
                    $out['tries']++;
                    $doc = $db->getCollection('poc_sessions')->findOneAndUpdate(
                        ['round' => $i, 'hash' => 'old'],
                        ['$set' => ['hash' => 'new-'.$this->option('id')]],
                        ['session' => $db->getSession()],
                    );
                    if ($doc === null) {
                        $out['lost']++;

                        return;
                    }
                    usleep(3000);
                    $db->table('poc_rotations')->insert(['round' => $i, 'by' => (int) $this->option('id')]);
                    $out['won']++;
                }, (int) $this->option('attempts'));
            } catch (Throwable $e) {
                $out['errors']++;
                $out['msgs'][class_basename($e)] = substr($e->getMessage(), 0, 120);
            }
        }

        return $out;
    }

    /** Hora pico: todos escriben el mismo operations_epoch. */
    private function load(): array
    {
        $db = DB::connection('mongodb');
        $end = (float) $this->option('start') + (int) $this->option('seconds');
        $this->waitRound(0);
        $lat = [];
        $out = ['ops' => 0, 'tries' => 0, 'busy' => 0, 'msgs' => []];
        $rate = (float) $this->option('rate');
        $backoff = (bool) $this->option('backoff');
        mt_srand((int) $this->option('id') + 1);
        while (microtime(true) < $end) {
            if ($rate > 0) {
                usleep((int) (-log(max(1e-6, mt_rand() / mt_getrandmax())) / $rate * 1e6));
            }
            $t = microtime(true);
            $try = 0;
            try {
                $db->transaction(function () use ($db, &$out, &$try, $backoff) {
                    if ($try++ > 0 && $backoff) {
                        usleep(random_int(0, (int) min(200000, 5000 * 2 ** $try)));
                    }
                    $out['tries']++;
                    $db->table('poc_branches')->where('code', 'B1')->increment('operations_epoch');
                    $db->table('poc_bookings')->where('slot', -1)->count();
                    $db->table('poc_load')->insert(['w' => (int) $this->option('id')]);
                }, (int) $this->option('attempts'));
                $out['ops']++;
                $lat[] = (microtime(true) - $t) * 1000;
            } catch (Throwable $e) {
                $out['busy']++;
                $out['msgs'][class_basename($e)] = substr($e->getMessage(), 0, 120);
            }
        }
        $out['lat'] = $lat;

        return $out;
    }
}
