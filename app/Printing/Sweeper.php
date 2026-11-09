<?php

namespace App\Printing;

use App\Models\PrintAgent;
use App\Models\PrintJob;
use App\Models\PrintJobAttempt;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;

/**
 * Plazos de impresión, como proceso interno:
 * - Reclamo vencido en CLAIMED: no se envió nada, así que vuelve a PENDING (PR-5).
 * - Reclamo vencido en PRINTING: no se sabe si salió, así que pasa a UNKNOWN (PR-5).
 * - Comanda PENDING vencida: pasa a FAILED con PRINT_JOB_EXPIRED en vez de salir tarde (PR-9).
 * - Agente sin latido: se publica print_agent.offline una vez por caída.
 *
 * Cada cambio va condicionado a la versión: si el agente o una persona se adelantó, se omite.
 */
final class Sweeper
{
    /** @return array{released: int, unknown: int, expired: int, offline: int} */
    public static function run(): array
    {
        $now = now();
        $count = ['released' => 0, 'unknown' => 0, 'expired' => 0, 'offline' => 0];

        $leases = PrintJob::query()->whereIn('status', [PrintJob::CLAIMED, PrintJob::PRINTING])->where('lease_until', '<', $now)->get();
        foreach ($leases as $job) {
            $printing = $job->status === PrintJob::PRINTING;
            $attempt = $job->current_attempt_uuid;
            $changes = $printing
                ? ['status' => PrintJob::UNKNOWN, 'lease_until' => null, 'last_error' => ['code' => 'LEASE_EXPIRED', 'message' => 'El agente dejó de responder mientras imprimía.']]
                : ['status' => PrintJob::PENDING, 'claimed_agent_id' => null, 'current_attempt_uuid' => null, 'lease_until' => null, 'next_attempt_at' => $now];
            $done = Mongo::transaction(function () use ($job, $changes, $printing, $attempt) {
                if (! $job->updateIfVersion((int) $job->version, $changes)) {
                    return false;
                }
                // En UNKNOWN el intento queda como vigente: si su resultado llega tarde, se acepta.
                PrintJobAttempt::query()->where('uuid', $attempt)
                    ->whereIn('status', [PrintJobAttempt::CLAIMED, PrintJobAttempt::PRINTING])
                    ->update(['status' => PrintJobAttempt::EXPIRED, 'finished_at' => now()]);
                if ($printing) {
                    Outbox::publish('print_job.unknown', 'print_job', $job->getKey(), (string) $job->branch_id, ['printJobId' => $job->uuid, 'type' => $job->type]);
                }

                return true;
            });
            $count[$printing ? 'unknown' : 'released'] += (int) $done;
        }

        $expired = PrintJob::query()->where('type', 'KITCHEN_TICKET')->where('status', PrintJob::PENDING)->where('expires_at', '<', $now)->get();
        foreach ($expired as $job) {
            $done = Mongo::transaction(function () use ($job) {
                $error = ['code' => 'PRINT_JOB_EXPIRED', 'message' => 'La comanda no se imprimió a tiempo; revisa con cocina antes de reintentar.'];
                if (! $job->updateIfVersion((int) $job->version, ['status' => PrintJob::FAILED, 'last_error' => $error])) {
                    return false;
                }
                Audit::record('print_job.expired', 'print_job', $job->getKey(), (string) $job->branch_id, changes: ['before' => ['status' => PrintJob::PENDING], 'after' => ['status' => PrintJob::FAILED]]);
                Outbox::publish('print_job.failed', 'print_job', $job->getKey(), (string) $job->branch_id, ['printJobId' => $job->uuid, 'type' => $job->type, 'code' => 'PRINT_JOB_EXPIRED']);

                return true;
            });
            $count['expired'] += (int) $done;
        }

        $silent = PrintAgent::query()->where('status', PrintAgent::ACTIVE)
            ->where('last_seen_at', '<', $now->copy()->subSeconds(config('restia.printing.agent_offline_seconds')))
            ->where('offline_notified', '!=', true)->get();
        foreach ($silent as $agent) {
            Mongo::transaction(function () use ($agent) {
                // Sin subir `version`: es telemetría, igual que el latido que la limpia.
                PrintAgent::query()->where('_id', $agent->getKey())->update(['offline_notified' => true]);
                Outbox::publish('print_agent.offline', 'print_agent', $agent->getKey(), (string) $agent->branch_id, ['printAgentId' => $agent->uuid]);
            });
            $count['offline']++;
        }

        return $count;
    }
}
