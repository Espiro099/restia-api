<?php

namespace App\Console\Commands;

use App\Printing\Simulator;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Agente de impresión simulado contra una API en marcha, por HTTP, como el agente real. El token se lee
 * de RESTIA_AGENT_TOKEN o se pide sin eco: nunca va en un argumento, para que no quede en el historial.
 */
class PrintAgentSim extends Command
{
    protected $signature = 'restia:print-agent-sim
        {--url=http://127.0.0.1:8000/api/v1 : Base de la API}
        {--fail=none : Falla simulada: none, not-sent, uncertain, crash o random}
        {--fail-rate=0.3 : Con --fail=random, probabilidad de que un trabajo falle}
        {--once : Una sola vuelta y termina}';

    protected $description = 'Agente de impresión simulado: reclama trabajos y los imprime en la terminal';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('restia:print-agent-sim no corre en producción.');

            return self::FAILURE;
        }
        $mode = (string) $this->option('fail');
        if (! in_array($mode, Simulator::MODES, true)) {
            $this->error('--fail debe ser uno de: '.implode(', ', Simulator::MODES).'.');

            return self::FAILURE;
        }
        $token = (string) (env('RESTIA_AGENT_TOKEN') ?: $this->secret('Token del agente (rsv_...)'));
        if ($token === '') {
            $this->error('Falta el token: usa RESTIA_AGENT_TOKEN o escríbelo cuando se pida.');

            return self::FAILURE;
        }
        $base = rtrim((string) $this->option('url'), '/');
        $send = function (string $method, string $path, array $body) use ($base, $token): array {
            $request = Http::withToken($token)->acceptJson()->timeout(10);
            $response = $method === 'GET' ? $request->get("$base/$path") : $request->send($method, "$base/$path", ['json' => (object) $body]);

            return [$response->status(), $response->json()];
        };
        $sim = new Simulator($send, fn (string $line) => $this->line($line), $mode, (float) $this->option('fail-rate'));

        try {
            if (! $sim->boot()) {
                return self::FAILURE;
            }
            do {
                $sim->tick();
                if (! $this->option('once')) {
                    sleep($sim->interval);
                }
            } while (! $this->option('once'));
        } catch (ConnectionException $e) {
            $this->error("No se pudo conectar con $base. ¿Corre php artisan serve?");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
