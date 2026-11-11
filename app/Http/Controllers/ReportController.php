<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainConflict;
use App\Http\Middleware\AuthorizeBranch;
use App\Models\Branch;
use App\Models\ReportRebuild;
use App\Reports\Reports;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Idempotency;
use App\Support\Mongo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Reportes (RP-1 a RP-3): lectura por sucursal y reconstrucción manual por ADMIN. */
class ReportController extends Controller
{
    /** Días que abarca una lectura; un rango mayor se pide por partes. */
    private const MAX_READ_DAYS = 92;

    /** Días que abarca una reconstrucción. */
    private const MAX_REBUILD_DAYS = 366;

    public function dailySales(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request->query(), self::MAX_READ_DAYS);

        return Api::data(Reports::dailySales(AuthorizeBranch::branch($request), $from, $to));
    }

    public function hourlyOccupancy(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request->query(), self::MAX_READ_DAYS);

        return Api::data(Reports::hourlyOccupancy(AuthorizeBranch::branch($request), $from, $to));
    }

    /** 202: la reconstrucción corre después, con restia:reports. Una sola activa por sucursal. */
    public function rebuild(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, ['from' => ['required', 'string', 'date_format:Y-m-d'], 'to' => ['required', 'string', 'date_format:Y-m-d']]);
        [$from, $to] = $this->range($in, self::MAX_REBUILD_DAYS);
        $branch = AuthorizeBranch::branch($request);
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($request->user()->getKey());

        try {
            [$status, $data] = Idempotency::run($branchId, $key, $actorId, 'rebuildReports', Idempotency::hash('rebuildReports', $in),
                function () use ($branch, $branchId, $actorId, $from, $to) {
                    if (ReportRebuild::query()->where('branch_id', $branchId)->where('active', true)->exists()) {
                        throw new DomainConflict('REPORT_REBUILD_IN_PROGRESS');
                    }
                    $job = ReportRebuild::query()->create([
                        'branch_id' => $branchId, 'from' => $from, 'to' => $to, 'status' => ReportRebuild::PENDING,
                        'active' => true, 'created_by' => $actorId, 'finished_at' => null,
                    ]);

                    return [202, self::present($branch, $job), $job->uuid];
                });
        } catch (Throwable $e) {
            // Dos peticiones simultáneas: el índice único parcial deja pasar una.
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('REPORT_REBUILD_IN_PROGRESS') : $e;
        }

        return Api::data($data, $status);
    }

    public function rebuildStatus(Request $request, string $branchId, string $rebuildId): JsonResponse
    {
        $branch = AuthorizeBranch::branch($request);
        $job = ReportRebuild::query()->forBranch($branch)->where('uuid', Floor::uuid($rebuildId))->first() ?? throw new NotFoundHttpException;

        return Api::data(self::present($branch, $job));
    }

    private static function present(Branch $branch, ReportRebuild $job): array
    {
        $time = fn ($t) => $t ? Carbon::instance($t)->utc()->format('Y-m-d\TH:i:s\Z') : null;

        return [
            'id' => $job->uuid,
            'branchId' => $branch->uuid,
            'from' => $job->from,
            'to' => $job->to,
            'status' => $job->status,
            'createdAt' => $time($job->created_at),
            'finishedAt' => $time($job->finished_at),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function range(array $input, int $maxDays): array
    {
        $v = Validator::make($input, [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $from = Carbon::parse($input['from']);
        $to = Carbon::parse($input['to']);
        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['to' => 'Debe ser igual o posterior a from.']);
        }
        if ($from->diffInDays($to) + 1 > $maxDays) {
            throw ValidationException::withMessages(['to' => "El rango no puede pasar de $maxDays días."]);
        }

        return [$from->toDateString(), $to->toDateString()];
    }
}
