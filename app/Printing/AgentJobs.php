<?php

namespace App\Printing;

use App\Exceptions\DomainConflict;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\PrintJobAttempt;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lo que hace el agente con un trabajo (PR-5, PR-6): reclamar, empezar y reportar el resultado.
 *
 * CLAIMED garantiza que no se envió nada: el agente no manda bytes hasta que `start` responde bien.
 * Cada reclamo es un intento, y solo el intento vigente avanza el trabajo; uno anterior recibe
 * PRINT_JOB_LEASE_LOST y el agente descarta el trabajo sin imprimir.
 */
final class AgentJobs
{
    /**
     * Reclamo atómico: cada `findOneAndUpdate` condicionado a PENDING entrega el trabajo a un solo
     * agente, sin transacción porque es un único documento. Lo más urgente y antiguo primero.
     *
     * @return list<array<string, mixed>>
     */
    public static function claim(PrintAgent $agent, Branch $branch, int $limit): array
    {
        $agentId = new ObjectId($agent->getKey());
        $printers = Printer::query()->where('agent_id', $agentId)->where('status', Printer::ACTIVE)->get(['_id'])
            ->map(fn ($p) => new ObjectId($p->getKey()))->all();
        if (! $printers) {
            return [];
        }
        $collection = DB::connection('mongodb')->getDatabase()->selectCollection('print_jobs');
        $claims = [];
        for ($i = 0; $i < $limit; $i++) {
            $now = now();
            $lease = $now->copy()->addSeconds(config('restia.printing.lease_seconds'));
            $attempt = (string) Str::uuid();
            $doc = $collection->findOneAndUpdate(
                [
                    'branch_id' => new ObjectId($branch->getKey()),
                    'printer_id' => ['$in' => $printers],
                    'status' => PrintJob::PENDING,
                    'next_attempt_at' => ['$lte' => new UTCDateTime($now)],
                    // Una comanda vencida no sale tarde (PR-9): la deja en FAILED el proceso interno.
                    '$or' => [['expires_at' => null], ['expires_at' => ['$gt' => new UTCDateTime($now)]]],
                ],
                [
                    '$set' => [
                        'status' => PrintJob::CLAIMED, 'claimed_agent_id' => $agentId, 'current_attempt_uuid' => $attempt,
                        'lease_until' => new UTCDateTime($lease), 'updated_at' => new UTCDateTime($now),
                    ],
                    '$inc' => ['version' => 1, 'attempts' => 1],
                ],
                ['sort' => ['priority_rank' => 1, 'created_at' => 1, '_id' => 1], 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
            );
            if (! $doc) {
                break;
            }
            // Si el agente se cae aquí, el trabajo queda CLAIMED sin intento y vuelve a PENDING al vencer.
            PrintJobAttempt::query()->create([
                'uuid' => $attempt, 'job_id' => $doc['_id'], 'agent_id' => $agentId, 'status' => PrintJobAttempt::CLAIMED,
                'claimed_at' => $now, 'started_at' => null, 'finished_at' => null, 'error_code' => null,
            ]);
            $job = PrintJob::query()->find((string) $doc['_id']);
            $document = (array) $job->document;
            $claims[] = [
                'attemptId' => $attempt,
                'job' => PrintJobs::one($branch, $job),
                'printerId' => Printer::query()->find((string) $job->printer_id)->uuid,
                'leaseUntil' => MobileAuth::time($job->lease_until),
                'document' => $job->original_job_id ? [...Documents::reprintBanner(), ...$document] : $document,
            ];
        }

        return $claims;
    }

    /** El agente va a enviar los bytes. Renueva el plazo. Repetirlo con el mismo intento no cambia nada. */
    public static function start(PrintAgent $agent, string $attemptUuid): PrintJob
    {
        return Mongo::transaction(function () use ($agent, $attemptUuid) {
            [$attempt, $job] = self::current($agent, $attemptUuid);
            if ($attempt->status === PrintJobAttempt::PRINTING && $job->status === PrintJob::PRINTING) {
                return $job;
            }
            if ($job->current_attempt_uuid !== $attempt->uuid || $attempt->status !== PrintJobAttempt::CLAIMED || $job->status !== PrintJob::CLAIMED || $job->lease_until->lte(now())) {
                throw new DomainConflict('PRINT_JOB_LEASE_LOST');
            }
            $lease = now()->addSeconds(config('restia.printing.lease_seconds'));
            if (! $job->updateIfVersion((int) $job->version, ['status' => PrintJob::PRINTING, 'lease_until' => $lease])) {
                throw new DomainConflict('PRINT_JOB_LEASE_LOST');
            }
            $attempt->update(['status' => PrintJobAttempt::PRINTING, 'started_at' => now()]);

            return $job;
        });
    }

    /**
     * Resultado del intento. Se acepta también si llega después de que el plazo dejó el trabajo en
     * UNKNOWN, para evitar revisiones manuales innecesarias; no si una persona ya lo resolvió.
     */
    public static function result(PrintAgent $agent, string $attemptUuid, string $outcome, ?string $errorCode, ?string $detail): PrintJob
    {
        return Mongo::transaction(function () use ($agent, $attemptUuid, $outcome, $errorCode, $detail) {
            [$attempt, $job] = self::current($agent, $attemptUuid);
            if ($attempt->status === $outcome) {
                return $job;
            }
            if ($job->current_attempt_uuid !== $attempt->uuid) {
                throw new DomainConflict('PRINT_JOB_LEASE_LOST');
            }
            $late = $job->status === PrintJob::UNKNOWN && $attempt->status === PrintJobAttempt::EXPIRED && $attempt->started_at !== null;
            if (! $late && ($job->status !== PrintJob::PRINTING || $attempt->status !== PrintJobAttempt::PRINTING)) {
                throw new DomainConflict('PRINT_JOB_LEASE_LOST');
            }
            $now = now();
            $release = ['claimed_agent_id' => null, 'current_attempt_uuid' => null, 'lease_until' => null];
            $event = null;
            if ($outcome === 'PRINTED') {
                $changes = ['status' => PrintJob::PRINTED, 'printed_at' => $now, 'lease_until' => null];
            } elseif ($outcome === 'NOT_SENT') {
                $error = ['code' => $errorCode ?: 'NOT_SENT', 'message' => $detail ?: 'La impresora no recibió el trabajo.'];
                if ((int) $job->attempts >= (int) config('restia.printing.max_attempts')) {
                    $changes = ['status' => PrintJob::FAILED, 'last_error' => $error] + $release;
                    $event = 'print_job.failed';
                } else {
                    // Espera creciente: 5 s, 10 s, 20 s...
                    $wait = config('restia.printing.retry_base_seconds') * 2 ** max(0, (int) $job->attempts - 1);
                    $changes = ['status' => PrintJob::PENDING, 'next_attempt_at' => $now->copy()->addSeconds($wait), 'last_error' => $error] + $release;
                }
            } else {
                // UNCERTAIN: la conexión se cortó después de empezar a enviar. Un UNKNOWN no se reintenta solo.
                $changes = ['status' => PrintJob::UNKNOWN, 'lease_until' => null,
                    'last_error' => ['code' => $errorCode ?: 'UNCERTAIN', 'message' => $detail ?: 'No se sabe si salió impreso.']];
                $event = $job->status === PrintJob::UNKNOWN ? null : 'print_job.unknown';
            }
            if (! $job->updateIfVersion((int) $job->version, $changes)) {
                throw new DomainConflict('PRINT_JOB_LEASE_LOST');
            }
            $attempt->update(['status' => $outcome, 'finished_at' => $now, 'error_code' => $errorCode]);
            if ($event) {
                Outbox::publish($event, 'print_job', $job->getKey(), (string) $job->branch_id, ['printJobId' => $job->uuid, 'type' => $job->type]);
            }

            return $job;
        });
    }

    /**
     * El intento debe ser de este agente; si no, 404. Quien llama revisa que siga siendo el vigente.
     *
     * @return array{0: PrintJobAttempt, 1: PrintJob}
     */
    private static function current(PrintAgent $agent, string $attemptUuid): array
    {
        $attempt = PrintJobAttempt::query()->where('uuid', strtolower($attemptUuid))->where('agent_id', new ObjectId($agent->getKey()))->first()
            ?? throw new NotFoundHttpException;

        return [$attempt, PrintJob::query()->find((string) $attempt->job_id)];
    }
}
