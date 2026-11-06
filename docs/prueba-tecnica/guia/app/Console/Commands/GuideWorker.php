<?php

namespace App\Console\Commands;

use App\Actions\OpenTableSession;
use App\Exceptions\DomainConflict;
use App\Exceptions\ServiceBusy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GuideWorker extends Command
{
    protected $signature = 'guide:worker {branch} {table} {--start=0} {--rounds=20} {--id=0}';

    public function handle(): int
    {
        $out = ['ok' => 0, 'assigned' => 0, 'busy' => 0, 'other' => []];
        $start = (float) $this->option('start');
        for ($i = 0; $i < (int) $this->option('rounds'); $i++) {
            if ($start + $i * 0.15 > microtime(true)) {
                time_sleep_until($start + $i * 0.15);
            }
            try {
                (new OpenTableSession)->run($this->argument('branch'), [$this->argument('table')."-$i"], 2, str_pad(dechex((int) $this->option('id') + 1), 24, 'a', STR_PAD_LEFT), (string) Str::uuid());
                $out['ok']++;
            } catch (DomainConflict $e) {
                $e->errorCode === 'TABLE_ALREADY_ASSIGNED' ? $out['assigned']++ : $out['other'][$e->errorCode] = 1;
            } catch (ServiceBusy) {
                $out['busy']++;
            } catch (\Throwable $e) {
                $out['other'][class_basename($e).': '.substr($e->getMessage(), 0, 90)] = 1;
            }
        }
        $this->line(json_encode($out));

        return 0;
    }
}
