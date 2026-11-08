<?php

namespace App\Printing;

use App\Catalog\Catalog;
use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/** Impresoras (PR-1, PR-2). Cada una pertenece a un agente, que es el único que reclama sus trabajos. */
final class Printers
{
    /** Estados de un trabajo que ya está en manos del agente. */
    public const BUSY_JOB_STATES = ['CLAIMED', 'PRINTING'];

    /**
     * @param  Collection<int, Printer>  $printers
     * @return list<array<string, mixed>>
     */
    public static function present(Branch $branch, Collection $printers): array
    {
        $agents = PrintAgent::query()->whereIn('_id', $printers->map(fn ($p) => new ObjectId((string) $p->agent_id))->all())->get()
            ->mapWithKeys(fn ($a) => [(string) $a->getKey() => $a->uuid]);

        return $printers->map(fn (Printer $p) => [
            'id' => $p->uuid,
            'branchId' => $branch->uuid,
            'agentId' => $agents->get((string) $p->agent_id),
            'code' => $p->code,
            'name' => $p->name,
            'connection' => self::connectionOut($p->connection),
            'paperWidth' => (int) $p->paper_width,
            'status' => $p->status,
            'version' => (int) $p->version,
        ])->values()->all();
    }

    /** @param array<string, mixed> $in */
    public static function create(Branch $branch, User $actor, array $in): Printer
    {
        $agent = self::agent($branch, $in['agentId']);

        return Catalog::create($branch, $actor, 'printer', 'CATALOG_CODE_TAKEN', function (ObjectId $branchId) use ($agent, $in) {
            self::assertAgentActive($agent);

            return Printer::query()->create([
                'branch_id' => $branchId, 'agent_id' => new ObjectId($agent->getKey()), 'code' => $in['code'], 'name' => $in['name'],
                'connection' => self::connectionIn($in['connection']), 'paper_width' => (int) $in['paperWidth'], 'status' => Printer::ACTIVE,
            ]);
        }, menu: false);
    }

    /** @param array<string, mixed> $in */
    public static function update(Branch $branch, User $actor, Printer $p, int $version, array $in): Printer
    {
        $agent = array_key_exists('agentId', $in) ? self::agent($branch, $in['agentId']) : null;

        return Catalog::change($branch, $actor, $p, $version, 'printer.updated', 'printer', function () use ($agent, $in) {
            $changes = [];
            if ($agent) {
                self::assertAgentActive($agent);
                $changes['agent_id'] = new ObjectId($agent->getKey());
            }
            if (array_key_exists('name', $in)) {
                $changes['name'] = $in['name'];
            }
            if (array_key_exists('connection', $in)) {
                $changes['connection'] = self::connectionIn($in['connection']);
            }
            if (array_key_exists('paperWidth', $in)) {
                $changes['paper_width'] = (int) $in['paperWidth'];
            }

            return $changes;
        }, menu: false);
    }

    /** Para desactivarla, el agente no puede tener un trabajo suyo a medias; los PENDING se mueven o cancelan. */
    public static function setStatus(Branch $branch, User $actor, Printer $p, int $version, string $status, string $reason): Printer
    {
        if ($p->status === $status) {
            return $p;
        }

        return Catalog::change($branch, $actor, $p, $version, 'printer.status_changed', 'printer', function (Printer $fresh) use ($status) {
            if ($status === Printer::INACTIVE) {
                $busy = DB::connection('mongodb')->table('print_jobs')->where('printer_id', new ObjectId($fresh->getKey()))
                    ->whereIn('status', self::BUSY_JOB_STATES)->exists();
                if ($busy) {
                    throw new DomainConflict('PRINTER_HAS_ACTIVE_JOBS');
                }
            }

            return ['status' => $status];
        }, reason: $reason, menu: false);
    }

    /** Impresora de la sucursal por su UUID, para validar una referencia: si no existe, 422 en ese campo. */
    public static function find(Branch $branch, string $uuid, string $field): Printer
    {
        return Printer::query()->forBranch($branch)->where('uuid', strtolower($uuid))->first()
            ?? throw ValidationException::withMessages([$field => 'La impresora no existe en esta sucursal.']);
    }

    private static function agent(Branch $branch, string $uuid): PrintAgent
    {
        return PrintAgent::query()->forBranch($branch)->where('uuid', strtolower($uuid))->first()
            ?? throw ValidationException::withMessages(['agentId' => 'El agente no existe en esta sucursal.']);
    }

    /** Se vuelve a leer dentro de la transacción: si alguien lo desactivó, no recibe la impresora. */
    private static function assertAgentActive(PrintAgent $agent): void
    {
        if (! PrintAgent::query()->where('_id', $agent->getKey())->where('status', PrintAgent::ACTIVE)->exists()) {
            throw new DomainConflict('PRINT_AGENT_INACTIVE');
        }
    }

    /** @param array<string, mixed> $c */
    private static function connectionIn(array $c): array
    {
        return $c['type'] === 'NETWORK'
            ? ['type' => 'NETWORK', 'host' => $c['host'], 'port' => (int) ($c['port'] ?? 9100)]
            : ['type' => 'USB', 'local_name' => $c['localName']];
    }

    private static function connectionOut(mixed $c): array
    {
        $c = (array) $c;

        return $c['type'] === 'NETWORK'
            ? ['type' => 'NETWORK', 'host' => $c['host'], 'port' => (int) $c['port']]
            : ['type' => 'USB', 'localName' => $c['local_name']];
    }
}
