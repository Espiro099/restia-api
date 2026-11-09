<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BranchResources;
use App\Http\Middleware\AuthenticateService;
use App\Http\Middleware\AuthorizeBranch;
use App\Models\Branch;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintStation;
use App\Printing\PrintAgents;
use App\Printing\Printers;
use App\Printing\PrintStations;
use App\Support\Api;
use App\Support\Floor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use MongoDB\BSON\ObjectId;

/**
 * Configuración de impresión (Decisiones_Printing): impresoras, rutas y agentes, que administra el
 * panel Web; y lo que el agente consulta al arrancar y en cada latido.
 */
class PrintingController extends Controller
{
    use BranchResources;

    private const CODE = ['required', 'string', 'regex:/^[A-Z0-9_-]{2,32}$/'];

    private const NAME = ['string', 'min:1', 'max:120'];

    // ---------------------------------------------------------------- Impresoras

    public function printers(Request $request): JsonResponse
    {
        return $this->list($request, Printer::class, ['code'], fn (Branch $b, $rows) => Printers::present($b, $rows));
    }

    public function printer(Request $request, string $branchId, string $printerId): JsonResponse
    {
        [$branch, $p] = $this->find($request, Printer::class, $printerId);

        return $this->printerResponse($branch, $p);
    }

    public function createPrinter(Request $request): JsonResponse
    {
        $in = Api::body($request, ['code' => self::CODE] + $this->printerRules(true));
        $branch = AuthorizeBranch::branch($request);

        return $this->printerResponse($branch, Printers::create($branch, $request->user(), $in), 201);
    }

    public function updatePrinter(Request $request, string $branchId, string $printerId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->patchBody($request, $this->printerRules(false));
        [$branch, $p] = $this->find($request, Printer::class, $printerId);

        return $this->printerResponse($branch, Printers::update($branch, $request->user(), $p, $version, $in));
    }

    public function printerStatus(Request $request, string $branchId, string $printerId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->statusBody($request, [Printer::ACTIVE, Printer::INACTIVE]);
        [$branch, $p] = $this->find($request, Printer::class, $printerId);

        return $this->printerResponse($branch, Printers::setStatus($branch, $request->user(), $p, $version, $in['status'], $in['reason']));
    }

    // ---------------------------------------------------------------- Rutas de impresión

    public function printStations(Request $request): JsonResponse
    {
        return $this->list($request, PrintStation::class, ['code'], fn (Branch $b, $rows) => PrintStations::present($b, $rows));
    }

    public function printStation(Request $request, string $branchId, string $printStationId): JsonResponse
    {
        [$branch, $r] = $this->find($request, PrintStation::class, $printStationId);

        return $this->routeResponse($branch, $r);
    }

    public function createPrintStation(Request $request): JsonResponse
    {
        $in = Api::body($request, [
            'code' => self::CODE,
            'name' => ['required', ...self::NAME],
            'stationId' => ['sometimes', 'string', 'uuid'],
            'printerId' => ['required', 'string', 'uuid'],
        ] + $this->jobTypeRules(true));
        $branch = AuthorizeBranch::branch($request);

        return $this->routeResponse($branch, PrintStations::create($branch, $request->user(), $in), 201);
    }

    public function updatePrintStation(Request $request, string $branchId, string $printStationId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->patchBody($request, [
            'name' => ['sometimes', ...self::NAME],
            'printerId' => ['sometimes', 'string', 'uuid'],
        ] + $this->jobTypeRules(false));
        [$branch, $r] = $this->find($request, PrintStation::class, $printStationId);

        return $this->routeResponse($branch, PrintStations::update($branch, $request->user(), $r, $version, $in));
    }

    public function printStationStatus(Request $request, string $branchId, string $printStationId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->statusBody($request, [PrintStation::ACTIVE, PrintStation::INACTIVE]);
        [$branch, $r] = $this->find($request, PrintStation::class, $printStationId);

        return $this->routeResponse($branch, PrintStations::setStatus($branch, $request->user(), $r, $version, $in['status'], $in['reason']));
    }

    // ---------------------------------------------------------------- Agentes

    public function printAgents(Request $request): JsonResponse
    {
        return $this->list($request, PrintAgent::class, ['name'], fn (Branch $b, $rows) => $rows->map(fn ($a) => PrintAgents::present($b, $a))->values()->all(), status: false);
    }

    public function printAgent(Request $request, string $branchId, string $printAgentId): JsonResponse
    {
        [$branch, $a] = $this->find($request, PrintAgent::class, $printAgentId);

        return $this->one(PrintAgents::present($branch, $a), $a);
    }

    public function createPrintAgent(Request $request): JsonResponse
    {
        $in = Api::body($request, ['serviceIdentityId' => ['required', 'string', 'uuid'], 'name' => ['required', ...self::NAME]]);
        $branch = AuthorizeBranch::branch($request);
        $a = PrintAgents::create($branch, $request->user(), $in);

        return $this->one(PrintAgents::present($branch, $a), $a, 201);
    }

    public function printAgentStatus(Request $request, string $branchId, string $printAgentId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->statusBody($request, [PrintAgent::ACTIVE, PrintAgent::INACTIVE]);
        [$branch, $a] = $this->find($request, PrintAgent::class, $printAgentId);
        $a = PrintAgents::setStatus($branch, $request->user(), $a, $version, $in['status'], $in['reason']);

        return $this->one(PrintAgents::present($branch, $a), $a);
    }

    // ---------------------------------------------------------------- Lo que usa el agente

    /** Al arrancar: quién es, qué impresoras activas atiende y cada cuánto consultar. */
    public function agentConfig(Request $request): JsonResponse
    {
        [$agent, $branch] = PrintAgents::forIdentity(AuthenticateService::identity($request));
        $printers = Printer::query()->where('agent_id', new ObjectId($agent->getKey()))->where('status', Printer::ACTIVE)->orderBy('code')->get();

        return Api::data([
            'agent' => PrintAgents::present($branch, $agent),
            'printers' => Printers::present($branch, $printers),
            'pollIntervalSeconds' => (int) config('restia.printing.poll_interval_seconds'),
        ]);
    }

    public function agentHeartbeat(Request $request): JsonResponse
    {
        [$agent, $branch] = PrintAgents::forIdentity(AuthenticateService::identity($request));
        $in = Api::body($request, [
            'agentVersion' => ['sometimes', 'string', 'max:40'],
            'printers' => ['sometimes', 'array', 'list', 'max:50'],
            'printers.*' => ['array:printerId,online,detail'],
            'printers.*.printerId' => ['required', 'string', 'uuid', 'distinct:ignore_case'],
            'printers.*.online' => ['required', 'boolean:strict'],
            'printers.*.detail' => ['sometimes', 'string', 'max:200'],
        ]);
        $agent = PrintAgents::heartbeat($agent, $in);

        return $this->one(PrintAgents::present($branch, $agent), $agent);
    }

    // ---------------------------------------------------------------- Ayudas

    private function printerResponse(Branch $branch, Printer $p, int $status = 200): JsonResponse
    {
        return $this->one(Printers::present($branch, collect([$p]))[0], $p, $status);
    }

    private function routeResponse(Branch $branch, PrintStation $r, int $status = 200): JsonResponse
    {
        return $this->one(PrintStations::present($branch, collect([$r]))[0], $r, $status);
    }

    /**
     * NETWORK lleva host y, opcional, puerto (9100 por omisión); USB lleva el nombre de la impresora en
     * el equipo del agente. Cada tipo rechaza los campos del otro.
     *
     * @return array<string, mixed>
     */
    private function printerRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'agentId' => [$req, 'string', 'uuid'],
            'name' => [$req, ...self::NAME],
            'connection' => [$req, 'array:type,host,port,localName'],
            'connection.type' => ['required_with:connection', 'string', Rule::in(['NETWORK', 'USB'])],
            'connection.host' => ['required_if:connection.type,NETWORK', 'prohibited_unless:connection.type,NETWORK', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:\[\]-]+$/'],
            'connection.port' => ['prohibited_unless:connection.type,NETWORK', 'integer', 'min:1', 'max:65535'],
            'connection.localName' => ['required_if:connection.type,USB', 'prohibited_unless:connection.type,USB', 'string', 'min:1', 'max:120'],
            'paperWidth' => [$req, 'integer', Rule::in([58, 80])],
        ];
    }

    /** @return array<string, mixed> */
    private function jobTypeRules(bool $create): array
    {
        return [
            'jobTypes' => [$create ? 'required' : 'sometimes', 'array', 'list', 'min:1', 'max:6'],
            'jobTypes.*' => ['string', Rule::in(PrintStation::TYPES), 'distinct'],
        ];
    }
}
