<?php

namespace App\Printing;

use App\Exceptions\DomainConflict;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\PrintJobAttempt;
use App\Models\PrintStation;
use App\Models\User;
use App\Support\Audit;
use App\Support\Idempotency;
use App\Support\Mongo;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/**
 * Trabajos de impresión: alta y lo que decide una persona (reintentar, resolver, cancelar y
 * reimprimir). Una falla nunca bloquea la comanda ni el cobro (PR-11): solo deja el trabajo en
 * FAILED o UNKNOWN y una alerta en outbox.
 */
final class PrintJobs
{
    /**
     * @param  Collection<int, PrintJob>  $jobs
     * @return list<array<string, mixed>>
     */
    public static function present(Branch $branch, Collection $jobs): array
    {
        if ($jobs->isEmpty()) {
            return [];
        }
        $uuids = fn (string $model, string $field) => $model::query()
            ->whereIn('_id', $jobs->pluck($field)->filter()->map(fn ($id) => new ObjectId((string) $id))->unique()->values()->all())
            ->get()->mapWithKeys(fn ($d) => [(string) $d->getKey() => $d->uuid]);
        $printers = $uuids(Printer::class, 'printer_id');
        $routes = $uuids(PrintStation::class, 'print_station_id');
        $originals = $uuids(PrintJob::class, 'original_job_id');
        $users = $uuids(User::class, 'created_by');
        $uuid = fn (Collection $map, $id) => $id ? $map->get((string) $id) : null;

        return $jobs->map(fn (PrintJob $j) => [
            'id' => $j->uuid,
            'branchId' => $branch->uuid,
            'type' => $j->type,
            'status' => $j->status,
            'priority' => $j->priority,
            'printerId' => $uuid($printers, $j->printer_id),
            'printStationId' => $uuid($routes, $j->print_station_id),
            'sourceType' => $j->source_type,
            'sourceId' => $j->source_uuid,
            'originalJobId' => $uuid($originals, $j->original_job_id),
            'reason' => $j->reason,
            'attempts' => (int) $j->attempts,
            'nextAttemptAt' => $j->status === PrintJob::PENDING ? MobileAuth::time($j->next_attempt_at) : null,
            'expiresAt' => MobileAuth::time($j->expires_at),
            'lastError' => $j->last_error ? ['code' => $j->last_error['code'], 'message' => $j->last_error['message']] : null,
            'createdBy' => $uuid($users, $j->created_by),
            'createdAt' => MobileAuth::time($j->created_at),
            'printedAt' => MobileAuth::time($j->printed_at),
            'version' => (int) $j->version,
        ])->values()->all();
    }

    public static function one(Branch $branch, PrintJob $job): array
    {
        return self::present($branch, collect([$job]))[0];
    }

    /**
     * @param  Collection<int, PrintJobAttempt>  $attempts
     * @return list<array<string, mixed>>
     */
    public static function presentAttempts(PrintJob $job, Collection $attempts): array
    {
        $agents = PrintAgent::query()->whereIn('_id', $attempts->map(fn ($a) => new ObjectId((string) $a->agent_id))->unique()->values()->all())->get()
            ->mapWithKeys(fn ($a) => [(string) $a->getKey() => $a->uuid]);

        return $attempts->map(fn (PrintJobAttempt $a) => [
            'id' => $a->uuid,
            'jobId' => $job->uuid,
            'agentId' => $agents->get((string) $a->agent_id),
            'status' => $a->status,
            'claimedAt' => MobileAuth::time($a->claimed_at),
            'startedAt' => MobileAuth::time($a->started_at),
            'finishedAt' => MobileAuth::time($a->finished_at),
            'errorCode' => $a->error_code,
        ])->values()->all();
    }

    /**
     * Crea un trabajo PENDING. Lo usan los disparadores automáticos y las peticiones a mano; quien llama
     * abre la transacción.
     *
     * @param  array<string, mixed>  $fields  type, printer_id, print_station_id, station_id, source_type,
     *                                        source_id, source_uuid, document y, opcionales, original_job_id,
     *                                        reason, created_by, expires_at, idempotency_key
     */
    public static function make(ObjectId $branchId, array $fields): PrintJob
    {
        $priority = PrintJob::PRIORITY[$fields['type']];

        return PrintJob::query()->create($fields + [
            'branch_id' => $branchId,
            'status' => PrintJob::PENDING,
            'priority' => $priority,
            'priority_rank' => PrintJob::RANK[$priority],
            'print_station_id' => null,
            'station_id' => null,
            'original_job_id' => null,
            'reason' => null,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'claimed_agent_id' => null,
            'current_attempt_uuid' => null,
            'lease_until' => null,
            'expires_at' => null,
            'printed_at' => null,
            'last_error' => null,
            'idempotency_key' => null,
            'created_by' => null,
        ]);
    }

    /**
     * Petición a mano (PR-3). Por ahora solo TEST: la precuenta y el recibo llegan con Checks y
     * Payments, y el reporte de caja con Cash, porque hoy no existe su origen.
     *
     * @param  array{type: string, sourceId: string, printStationId?: string}  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function request(Branch $branch, User $actor, string $key, array $in): array
    {
        if ($in['type'] !== 'TEST') {
            throw ValidationException::withMessages(['sourceId' => 'El origen de este documento todavía no existe en esta versión.']);
        }
        $printer = Printers::find($branch, $in['sourceId'], 'sourceId');
        if ($printer->status !== Printer::ACTIVE) {
            throw ValidationException::withMessages(['sourceId' => 'La impresora está inactiva.']);
        }
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        return Idempotency::run($branchId, $key, $actorId, 'createPrintJob', Idempotency::hash('createPrintJob', $in), function () use ($branch, $branchId, $actorId, $printer) {
            $job = self::make($branchId, [
                'type' => 'TEST', 'printer_id' => new ObjectId($printer->getKey()), 'source_type' => 'printer',
                'source_id' => new ObjectId($printer->getKey()), 'source_uuid' => $printer->uuid,
                'document' => Documents::test($branch, $printer), 'created_by' => $actorId,
            ]);
            Audit::record('print_job.requested', 'print_job', $job->getKey(), $branchId, $actorId, changes: ['after' => ['type' => 'TEST', 'printer_id' => (string) $printer->getKey()]]);

            return [201, self::one($branch, $job), $job->uuid];
        });
    }

    /**
     * Reintento (PR-8): el mismo trabajo, porque no salió. Puede ir a otra impresora. Una persona ya
     * decidió que se imprima, así que deja de vencer y vuelve a tener sus intentos automáticos.
     */
    public static function retry(Branch $branch, User $actor, PrintJob $job, int $version, ?string $printerUuid): PrintJob
    {
        $printer = $printerUuid ? Printers::find($branch, $printerUuid, 'printerId') : null;
        if ($printer && $printer->status !== Printer::ACTIVE) {
            throw ValidationException::withMessages(['printerId' => 'La impresora está inactiva.']);
        }

        return self::change($actor, $job, $version, 'print_job.retried', null, function (PrintJob $fresh) use ($printer) {
            if ($fresh->status === PrintJob::UNKNOWN) {
                throw new DomainConflict('PRINT_JOB_UNKNOWN');
            }
            self::assertState($fresh, [PrintJob::FAILED]);
            $changes = ['status' => PrintJob::PENDING, 'attempts' => 0, 'next_attempt_at' => now(), 'expires_at' => null, 'last_error' => null];
            if ($printer) {
                $changes['printer_id'] = new ObjectId($printer->getKey());
            }

            return $changes;
        });
    }

    /** PR-7: una persona revisa la impresora y declara si salió. Si no, queda FAILED y se puede reintentar. */
    public static function resolve(Branch $branch, User $actor, PrintJob $job, int $version, string $outcome, string $reason): PrintJob
    {
        return self::change($actor, $job, $version, 'print_job.resolved', $reason, function (PrintJob $fresh) use ($outcome) {
            self::assertState($fresh, [PrintJob::UNKNOWN]);

            return $outcome === 'PRINTED'
                ? ['status' => PrintJob::PRINTED, 'printed_at' => now()]
                : ['status' => PrintJob::FAILED, 'last_error' => ['code' => 'NOT_PRINTED', 'message' => 'Se revisó y no salió impreso.']];
        });
    }

    public static function cancel(Branch $branch, User $actor, PrintJob $job, int $version, string $reason): PrintJob
    {
        return self::change($actor, $job, $version, 'print_job.cancelled', $reason, function (PrintJob $fresh) {
            self::assertState($fresh, [PrintJob::PENDING, PrintJob::FAILED, PrintJob::UNKNOWN]);

            return ['status' => PrintJob::CANCELLED];
        });
    }

    /**
     * Reimpresión (PR-8): trabajo nuevo con el contenido original, ligado al que se reimprime. La marca
     * REIMPRESIÓN se agrega al entregarlo al agente, para no repetirla al reimprimir una reimpresión.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function reprint(Branch $branch, User $actor, string $key, PrintJob $job, string $reason): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        $hash = Idempotency::hash('reprintPrintJob', ['job' => $job->uuid, 'reason' => $reason]);

        return Idempotency::run($branchId, $key, $actorId, 'reprintPrintJob', $hash, function () use ($branch, $branchId, $actorId, $job, $reason) {
            $fresh = PrintJob::query()->find($job->getKey());
            self::assertState($fresh, [PrintJob::PRINTED, PrintJob::UNKNOWN]);
            $copy = self::make($branchId, [
                'type' => $fresh->type, 'printer_id' => $fresh->printer_id, 'print_station_id' => $fresh->print_station_id,
                'station_id' => $fresh->station_id, 'source_type' => $fresh->source_type, 'source_id' => $fresh->source_id,
                'source_uuid' => $fresh->source_uuid, 'document' => $fresh->document, 'original_job_id' => new ObjectId($fresh->getKey()),
                'reason' => $reason, 'created_by' => $actorId,
            ]);
            Audit::record('print_job.reprinted', 'print_job', $copy->getKey(), $branchId, $actorId, reason: $reason, changes: [
                'after' => ['original_job_id' => (string) $fresh->getKey(), 'type' => $fresh->type],
            ]);

            return [201, self::one($branch, $copy), $copy->uuid];
        });
    }

    /** @param list<string> $states */
    private static function assertState(PrintJob $job, array $states): void
    {
        if (! in_array($job->status, $states, true)) {
            throw new DomainConflict('PRINT_JOB_INVALID_STATE');
        }
    }

    /** Cambio de una persona, con If-Match y auditoría. Un resultado tardío del agente choca con la versión. */
    private static function change(User $actor, PrintJob $job, int $version, string $action, ?string $reason, \Closure $work): PrintJob
    {
        Mongo::transaction(function () use ($actor, $job, $version, $action, $reason, $work) {
            $fresh = PrintJob::query()->find($job->getKey());
            if ((int) $fresh->version !== $version) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            $before = $fresh->status;
            $changes = $work($fresh);
            if (! $fresh->updateIfVersion($version, $changes)) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            $after = ['status' => $fresh->status];
            if (isset($changes['printer_id'])) {
                $after['printer_id'] = (string) $changes['printer_id'];
            }
            Audit::record($action, 'print_job', $fresh->getKey(), $fresh->branch_id, new ObjectId($actor->getKey()), reason: $reason, changes: [
                'before' => ['status' => $before], 'after' => $after,
            ]);
        });

        return $job->refresh();
    }
}
