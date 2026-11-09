<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainConflict;
use App\Http\Controllers\Concerns\BranchResources;
use App\Http\Middleware\AuthenticateService;
use App\Http\Middleware\AuthorizeBranch;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\PrintJobAttempt;
use App\Models\PrintStation;
use App\Printing\AgentJobs;
use App\Printing\PrintAgents;
use App\Printing\PrintJobs;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/** Trabajos de impresión: lo que ven y deciden las personas, y lo que reclama y reporta el agente. */
class PrintJobController extends Controller
{
    use BranchResources;

    private const REASON = ['required', 'string', 'min:3', 'max:500'];

    /** Del más reciente al más antiguo. */
    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $v = Validator::make($request->query(), [
            'status' => ['sometimes', 'string', Rule::in([PrintJob::PENDING, PrintJob::CLAIMED, PrintJob::PRINTING, PrintJob::PRINTED, PrintJob::FAILED, PrintJob::UNKNOWN, PrintJob::CANCELLED])],
            'type' => ['sometimes', 'string', Rule::in(PrintStation::TYPES)],
            'printerId' => ['sometimes', 'string', 'uuid'],
            'sourceId' => ['sometimes', 'string', 'uuid'],
            'from' => ['sometimes', 'string', 'date'],
            'to' => ['sometimes', 'string', 'date', 'after_or_equal:from'],
            'page' => ['sometimes'], 'perPage' => ['sometimes'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $q = $v->validated();
        $branch = AuthorizeBranch::branch($request);
        $query = PrintJob::query()->forBranch($branch);
        foreach (['status' => 'status', 'type' => 'type'] as $param => $field) {
            if (isset($q[$param])) {
                $query->where($field, $q[$param]);
            }
        }
        if (isset($q['printerId'])) {
            $printer = Printer::query()->forBranch($branch)->where('uuid', strtolower($q['printerId']))->first();
            $query->whereIn('printer_id', $printer ? [new ObjectId($printer->getKey())] : []);
        }
        if (isset($q['sourceId'])) {
            $query->where('source_uuid', strtolower($q['sourceId']));
        }
        if (isset($q['from'])) {
            $query->where('created_at', '>=', Carbon::parse($q['from']));
        }
        if (isset($q['to'])) {
            $query->where('created_at', '<=', Carbon::parse($q['to']));
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at', 'desc')->orderBy('_id', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(PrintJobs::present($branch, $rows), meta: Api::pagination($page, $perPage, $total));
    }

    public function show(Request $request, string $branchId, string $printJobId): JsonResponse
    {
        [$branch, $job] = $this->find($request, PrintJob::class, $printJobId);

        return $this->one(PrintJobs::one($branch, $job), $job);
    }

    public function attempts(Request $request, string $branchId, string $printJobId): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        [, $job] = $this->find($request, PrintJob::class, $printJobId);
        $query = PrintJobAttempt::query()->where('job_id', new ObjectId($job->getKey()));
        $total = (clone $query)->count();
        $rows = $query->orderBy('claimed_at')->orderBy('_id')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(PrintJobs::presentAttempts($job, $rows), meta: Api::pagination($page, $perPage, $total));
    }

    /** CASH_SHIFT_REPORT exige además cash.read, porque lleva importes; TEST exige printing.manage. */
    public function create(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'type' => ['required', 'string', Rule::in(['PRE_CHECK', 'PAYMENT_RECEIPT', 'CASH_SHIFT_REPORT', 'TEST'])],
            'sourceId' => ['required', 'string', 'uuid'],
            'printStationId' => ['sometimes', 'string', 'uuid'],
        ]);
        $extra = ['CASH_SHIFT_REPORT' => 'cash.read', 'TEST' => 'printing.manage'][$in['type']] ?? null;
        if ($extra && ! AuthorizeBranch::membership($request)->can($extra)) {
            throw new DomainConflict('FORBIDDEN');
        }
        [$status, $data] = PrintJobs::request(AuthorizeBranch::branch($request), $request->user(), $key, $in);

        return $this->created($data, $status);
    }

    public function retry(Request $request, string $branchId, string $printJobId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['printerId' => ['sometimes', 'string', 'uuid']]);
        [$branch, $job] = $this->find($request, PrintJob::class, $printJobId);
        $job = PrintJobs::retry($branch, $request->user(), $job, $version, $in['printerId'] ?? null);

        return $this->one(PrintJobs::one($branch, $job), $job);
    }

    public function resolve(Request $request, string $branchId, string $printJobId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['outcome' => ['required', 'string', Rule::in(['PRINTED', 'NOT_PRINTED'])], 'reason' => self::REASON]);
        [$branch, $job] = $this->find($request, PrintJob::class, $printJobId);
        $job = PrintJobs::resolve($branch, $request->user(), $job, $version, $in['outcome'], $in['reason']);

        return $this->one(PrintJobs::one($branch, $job), $job);
    }

    public function cancel(Request $request, string $branchId, string $printJobId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => self::REASON]);
        [$branch, $job] = $this->find($request, PrintJob::class, $printJobId);
        $job = PrintJobs::cancel($branch, $request->user(), $job, $version, $in['reason']);

        return $this->one(PrintJobs::one($branch, $job), $job);
    }

    public function reprint(Request $request, string $branchId, string $printJobId): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, ['reason' => self::REASON]);
        [$branch, $job] = $this->find($request, PrintJob::class, $printJobId);
        [$status, $data] = PrintJobs::reprint($branch, $request->user(), $key, $job, $in['reason']);

        return $this->created($data, $status);
    }

    // ---------------------------------------------------------------- Agente

    public function claim(Request $request): JsonResponse
    {
        [$agent, $branch] = PrintAgents::forIdentity(AuthenticateService::identity($request));
        $in = Api::body($request, ['limit' => ['sometimes', 'integer', 'min:1', 'max:20']]);

        return Api::data(AgentJobs::claim($agent, $branch, (int) ($in['limit'] ?? 5)));
    }

    public function start(Request $request, string $attemptId): JsonResponse
    {
        [$agent, $branch] = PrintAgents::forIdentity(AuthenticateService::identity($request));
        Api::body($request, []);
        $job = AgentJobs::start($agent, Floor::uuid($attemptId));

        return $this->one(PrintJobs::one($branch, $job), $job);
    }

    public function result(Request $request, string $attemptId): JsonResponse
    {
        [$agent, $branch] = PrintAgents::forIdentity(AuthenticateService::identity($request));
        $in = Api::body($request, [
            'outcome' => ['required', 'string', Rule::in(['PRINTED', 'NOT_SENT', 'UNCERTAIN'])],
            'errorCode' => ['sometimes', 'string', 'max:60'],
            'detail' => ['sometimes', 'string', 'max:500'],
        ]);
        $job = AgentJobs::result($agent, Floor::uuid($attemptId), $in['outcome'], $in['errorCode'] ?? null, $in['detail'] ?? null);

        return $this->one(PrintJobs::one($branch, $job), $job);
    }

    /** Con Idempotency-Key la repetición devuelve la respuesta original; su ETag es la versión guardada en ella. */
    private function created(array $data, int $status): JsonResponse
    {
        $response = Api::data($data, $status);
        $response->headers->set('ETag', Floor::versionEtag((int) $data['version']));

        return $response;
    }
}
