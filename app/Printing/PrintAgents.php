<?php

namespace App\Printing;

use App\Catalog\Catalog;
use App\Exceptions\DomainConflict;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\ServiceIdentity;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Throwable;

/**
 * Agentes de impresión (PR-1): un programa en el restaurante que consulta trabajos hacia afuera, sin
 * puertos abiertos. Se autentica con una identidad de servicio PRINTING_AGENT; una identidad admite
 * un solo agente.
 */
final class PrintAgents
{
    /** @return array<string, mixed> */
    public static function present(Branch $branch, PrintAgent $a): array
    {
        return [
            'id' => $a->uuid,
            'branchId' => $branch->uuid,
            'serviceIdentityId' => ServiceIdentity::query()->find((string) $a->service_identity_id)?->uuid,
            'name' => $a->name,
            'status' => $a->status,
            'lastSeenAt' => MobileAuth::time($a->last_seen_at),
            'agentVersion' => $a->agent_version,
            'version' => (int) $a->version,
        ];
    }

    /** @param array{serviceIdentityId: string, name: string} $in */
    public static function create(Branch $branch, User $actor, array $in): PrintAgent
    {
        if (! $branch->isActive()) {
            throw new DomainConflict('BRANCH_INACTIVE');
        }
        $branchId = new ObjectId($branch->getKey());
        $identity = ServiceIdentity::query()->where('uuid', strtolower($in['serviceIdentityId']))->first();
        // Una identidad que no está autorizada para la sucursal se trata como inexistente: no se revela.
        $authorized = $identity && in_array((string) $branchId, array_map('strval', $identity->branch_ids ?? []), true);
        if (! $authorized) {
            throw ValidationException::withMessages(['serviceIdentityId' => 'La identidad no existe o no está autorizada para esta sucursal.']);
        }
        if ($identity->type !== ServiceIdentity::PRINTING_AGENT) {
            throw ValidationException::withMessages(['serviceIdentityId' => 'La identidad no es de tipo PRINTING_AGENT.']);
        }
        $taken = fn () => ValidationException::withMessages(['serviceIdentityId' => 'La identidad ya tiene un agente.']);

        try {
            return Mongo::transaction(function () use ($branchId, $actor, $identity, $in, $taken) {
                if (! ServiceIdentity::query()->where('_id', $identity->getKey())->where('status', ServiceIdentity::ACTIVE)->exists()) {
                    throw new DomainConflict('SERVICE_IDENTITY_REVOKED');
                }
                if (PrintAgent::query()->where('service_identity_id', new ObjectId($identity->getKey()))->exists()) {
                    throw $taken();
                }
                $a = PrintAgent::query()->create([
                    'branch_id' => $branchId, 'service_identity_id' => new ObjectId($identity->getKey()), 'name' => $in['name'],
                    'status' => PrintAgent::ACTIVE, 'last_seen_at' => null, 'agent_version' => null,
                ]);
                Audit::record('print_agent.created', 'print_agent', $a->getKey(), $branchId, new ObjectId($actor->getKey()), changes: [
                    'after' => ['name' => $a->name, 'service_identity_id' => (string) $identity->getKey()],
                ]);
                Outbox::publish('print_agent.created', 'print_agent', $a->getKey(), $branchId, ['printAgentId' => $a->uuid]);

                return $a;
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? $taken() : $e;
        }
    }

    public static function setStatus(Branch $branch, User $actor, PrintAgent $a, int $version, string $status, string $reason): PrintAgent
    {
        if ($a->status === $status) {
            return $a;
        }

        return Catalog::change($branch, $actor, $a, $version, 'print_agent.status_changed', 'print_agent', fn () => ['status' => $status], reason: $reason, menu: false);
    }

    /**
     * Agente del token: debe existir, estar activo, y su sucursal debe seguir activa y autorizada en la
     * identidad. Cualquier otra cosa responde 403 sin decir cuál falló.
     *
     * @return array{0: PrintAgent, 1: Branch}
     */
    public static function forIdentity(ServiceIdentity $identity): array
    {
        $agent = PrintAgent::query()->where('service_identity_id', new ObjectId($identity->getKey()))->first();
        $branch = $agent ? Branch::query()->find((string) $agent->branch_id) : null;
        $authorized = $branch && in_array((string) $branch->getKey(), array_map('strval', $identity->branch_ids ?? []), true);
        if (! $agent || $agent->status !== PrintAgent::ACTIVE || ! $authorized || ! $branch->isActive()) {
            throw new DomainConflict('FORBIDDEN');
        }

        return [$agent, $branch];
    }

    /**
     * Latido: el agente avisa que sigue vivo y cómo ve sus impresoras. Es telemetría, así que no sube
     * `version`: si lo hiciera, cada latido rompería el If-Match de quien edita el agente en el panel.
     *
     * @param  array{agentVersion?: string, printers?: list<array{printerId: string, online: bool, detail?: string}>}  $in
     */
    public static function heartbeat(PrintAgent $agent, array $in): PrintAgent
    {
        $reports = $in['printers'] ?? [];
        $printers = Printer::query()->where('agent_id', new ObjectId($agent->getKey()))
            ->whereIn('uuid', array_map(fn ($r) => strtolower($r['printerId']), $reports))->get()->keyBy('uuid');
        $errors = [];
        foreach ($reports as $i => $r) {
            if (! $printers->has(strtolower($r['printerId']))) {
                $errors["printers.$i.printerId"] = 'La impresora no pertenece a este agente.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $now = now();
        $fields = ['last_seen_at' => $now];
        if (array_key_exists('agentVersion', $in)) {
            $fields['agent_version'] = $in['agentVersion'];
        }
        PrintAgent::query()->where('_id', $agent->getKey())->update($fields);
        foreach ($reports as $r) {
            Printer::query()->where('_id', $printers->get(strtolower($r['printerId']))->getKey())->update([
                'online' => (bool) $r['online'], 'online_detail' => $r['detail'] ?? null, 'online_reported_at' => $now,
            ]);
        }

        return $agent->refresh();
    }
}
