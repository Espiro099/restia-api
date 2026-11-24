<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainConflict;
use App\PublicSite\Assistant;
use App\PublicSite\AssistantUnavailable;
use App\PublicSite\Phone;
use App\PublicSite\Site;
use App\Support\Api;
use App\Support\Idempotency;
use App\Support\Throttle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sitio público de La Mesa (Decisiones_Public_Site): sin cuenta ni cookies. Los límites van por IP; cada
 * operación cuenta aparte para que el chat no gaste el cupo de las reservas.
 */
class PublicSiteController extends Controller
{
    public function branches(Request $request, Assistant $assistant): JsonResponse
    {
        $this->limit($request, 'reads');

        return Api::data(Site::branches(), meta: ['assistant' => $assistant->available()]);
    }

    public function availability(Request $request, string $code): JsonResponse
    {
        $this->limit($request, 'reads');
        $in = Validator::make($request->query(), [
            'date' => ['required', 'string', 'date_format:Y-m-d'],
            'partySize' => ['required', 'integer', 'min:1', 'max:'.Site::MAX_PARTY],
        ])->validate();
        $branch = Site::branch($code);

        return Api::data(['date' => $in['date'], 'partySize' => (int) $in['partySize'], 'slots' => Site::slots($branch, $in['date'], (int) $in['partySize'])]);
    }

    public function createRequest(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'branchCode' => ['required', 'string', 'max:32'],
            'date' => ['required', 'string', 'date_format:Y-m-d'],
            'time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'partySize' => ['required', 'integer', 'min:1', 'max:'.Site::MAX_PARTY],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        $in['phone'] = Phone::e164($in['phone']) ?? throw ValidationException::withMessages(['phone' => 'Escribe un teléfono de 10 dígitos.']);
        $this->limit($request, 'requests');
        $branch = Site::branch($in['branchCode']);
        unset($in['branchCode']);
        [$status, $data] = Site::request($branch, $key, $in);

        return Api::data($data, $status, noStore: true);
    }

    public function lookupRequest(Request $request): JsonResponse
    {
        $this->limit($request, 'lookups');
        $in = Api::body($request, [
            'branchCode' => ['required', 'string', 'max:32'],
            'folio' => ['required', 'string', 'min:6', 'max:16'],
            'phone' => ['required', 'string', 'max:20'],
        ]);
        $phone = Phone::e164($in['phone']) ?? throw ValidationException::withMessages(['phone' => 'Escribe un teléfono de 10 dígitos.']);

        return Api::data(Site::lookup(Site::branch($in['branchCode']), $in['folio'], $phone), noStore: true);
    }

    /** SITE-3: una pregunta al asistente, con la conversación completa que guarda la página. */
    public function assistant(Request $request, Assistant $assistant): JsonResponse
    {
        $in = Api::body($request, [
            'messages' => ['required', 'array', 'list', 'min:1', 'max:'.Assistant::MAX_TURNS],
            'messages.*' => ['array:role,content'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'min:1', 'max:'.Assistant::MAX_CHARS],
        ]);
        $messages = array_map(fn ($m) => ['role' => $m['role'], 'content' => trim($m['content'])], $in['messages']);
        foreach ($messages as $i => $m) {
            if ($m['role'] !== ($i % 2 === 0 ? 'user' : 'assistant') || $m['content'] === '') {
                throw ValidationException::withMessages(["messages.$i.role" => 'La conversación debe alternar cliente y asistente, empezando por el cliente.']);
            }
        }
        if (end($messages)['role'] !== 'user') {
            throw ValidationException::withMessages(['messages' => 'El último mensaje debe ser del cliente.']);
        }
        // Solo cuentan las preguntas válidas: son las que llegan a la API de Claude.
        $this->limit($request, 'assistant');
        if (! $assistant->available()) {
            throw new DomainConflict('SERVICE_BUSY');
        }
        try {
            return Api::data($assistant->answer($messages), noStore: true);
        } catch (AssistantUnavailable) {
            throw new DomainConflict('SERVICE_BUSY');
        }
    }

    private function limit(Request $request, string $what): void
    {
        Throttle::hit("site-$what|".$request->ip(), config("restia.site.limits.$what"));
    }
}
