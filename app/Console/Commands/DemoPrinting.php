<?php

namespace App\Console\Commands;

use App\Identity\ServiceIdentities;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\PrintAgent;
use App\Models\Station;
use App\Models\User;
use App\Printing\PrintAgents;
use App\Printing\Printers;
use App\Printing\PrintStations;
use Illuminate\Console\Command;
use MongoDB\BSON\ObjectId;

/**
 * Configuración de impresión de prueba, para usar con restia:print-agent-sim: una identidad de servicio,
 * su agente, y una impresora y una ruta de comandas por cada estación activa. Muestra el token una sola
 * vez. No corre en producción.
 */
class DemoPrinting extends Command
{
    protected $signature = 'restia:demo-printing {--branch= : Código de la sucursal; por omisión, la única que exista}';

    protected $description = 'Crea un agente de impresión de prueba con una impresora por estación';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('restia:demo-printing no corre en producción.');

            return self::FAILURE;
        }
        $branch = DemoOrder::branch($this->option('branch'));
        if (! $branch) {
            $this->error('Indica la sucursal con --branch; ejecuta antes restia:install y restia:demo.');

            return self::FAILURE;
        }
        $admin = $this->admin($branch);
        if (! $admin) {
            $this->error('La sucursal no tiene un ADMIN activo; ejecuta antes restia:install.');

            return self::FAILURE;
        }
        if (PrintAgent::query()->forBranch($branch)->exists()) {
            $this->error('La sucursal ya tiene un agente de impresión; restia:demo-printing solo configura una sucursal sin agentes.');

            return self::FAILURE;
        }
        $stations = Station::query()->forBranch($branch)->where('status', Station::ACTIVE)->orderBy('code')->get();
        if ($stations->isEmpty()) {
            $this->error('La sucursal no tiene estaciones activas; ejecuta antes restia:demo.');

            return self::FAILURE;
        }

        $managed = [(string) $branch->getKey()];
        [$identity, $token] = ServiceIdentities::create($admin, $managed, [
            'name' => 'Agente de prueba '.$branch->code, 'type' => 'PRINTING_AGENT', 'branchIds' => [$branch->uuid],
        ]);
        $agent = PrintAgents::create($branch, $admin, ['serviceIdentityId' => $identity->uuid, 'name' => 'Agente simulado']);
        $rows = [];
        foreach ($stations as $station) {
            $printer = Printers::create($branch, $admin, [
                'agentId' => $agent->uuid, 'code' => 'SIM-'.$station->code, 'name' => 'Simulada '.$station->name,
                'connection' => ['type' => 'USB', 'localName' => 'Simulada '.$station->name], 'paperWidth' => 80,
            ]);
            PrintStations::create($branch, $admin, [
                'code' => 'COMANDAS-'.$station->code, 'name' => 'Comandas de '.$station->name, 'stationId' => $station->uuid,
                'jobTypes' => ['KITCHEN_TICKET', 'VOID_NOTICE'], 'printerId' => $printer->uuid,
            ]);
            $rows[] = [$station->name, $printer->code];
        }

        $this->info('Listo: agente "Agente simulado" con una impresora por estación.');
        $this->table(['Estación', 'Impresora'], $rows);
        $this->newLine();
        $this->warn('Token del agente. Se muestra una sola vez; guárdalo ahora:');
        $this->line($token);
        $this->newLine();
        $this->line('Para arrancar el agente simulado en PowerShell:');
        $this->line('  $env:RESTIA_AGENT_TOKEN = "<token>"');
        $this->line('  php artisan restia:print-agent-sim');

        return self::SUCCESS;
    }

    private function admin(Branch $branch): ?User
    {
        $membership = Membership::query()->where('branch_id', new ObjectId($branch->getKey()))->where('role', 'ADMIN')
            ->where('status', Membership::ACTIVE)->first();

        return $membership ? User::query()->where('_id', new ObjectId((string) $membership->user_id))->where('status', User::ACTIVE)->first() : null;
    }
}
