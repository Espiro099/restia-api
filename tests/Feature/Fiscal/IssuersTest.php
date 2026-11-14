<?php

namespace Tests\Feature\Fiscal;

use App\Fiscal\Pac\CsdInfo;
use App\Fiscal\Pac\Pac;
use App\Fiscal\Pac\PacFailure;
use App\Fiscal\Pac\StampRequest;
use App\Fiscal\Pac\StampResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Floor\FloorTestCase;

/** Emisores fiscales (F-12, F-13) con el PAC simulado. */
class IssuersTest extends FloorTestCase
{
    use ConfiguresIssuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
    }

    private function create(): array
    {
        return $this->as('admin', 'POST', '/fiscal-issuers', $this->issuerBody)->assertCreated()->json('data');
    }

    public function test_alta_lectura_y_cambios(): void
    {
        $this->as('gerente', 'POST', '/fiscal-issuers', $this->issuerBody)->assertStatus(403);
        $this->as('gerente', 'GET', '/fiscal-issuers')->assertStatus(403);
        $this->as('admin', 'POST', '/fiscal-issuers', ['rfc' => 'eku9003173c9'] + $this->issuerBody)->assertStatus(422);
        $this->as('admin', 'POST', '/fiscal-issuers', ['taxRegime' => '60'] + $this->issuerBody)->assertStatus(422);
        $this->as('admin', 'POST', '/fiscal-issuers', ['csd' => 'x'] + $this->issuerBody)->assertStatus(422);

        $issuer = $this->as('admin', 'POST', '/fiscal-issuers', $this->issuerBody)->assertCreated()->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.status', 'INACTIVE')->assertJsonPath('data.globalPeriodicity', 'MONTHLY')->assertJsonPath('data.csd', null)->json('data');
        $this->as('admin', 'POST', '/fiscal-issuers', $this->issuerBody)->assertStatus(409)->assertJsonPath('error.code', 'FISCAL_ISSUER_RFC_TAKEN');
        $this->as('admin', 'GET', '/fiscal-issuers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 1);
        $this->as('admin', 'GET', "/fiscal-issuers/{$issuer['id']}")->assertOk()->assertJsonPath('data.rfc', 'EKU9003173C9');
        $this->as('admin', 'GET', '/fiscal-issuers/'.fake()->uuid())->assertNotFound();

        // El RFC no cambia: un RFC distinto es otro emisor.
        $this->as('admin', 'PATCH', "/fiscal-issuers/{$issuer['id']}", ['rfc' => 'XIA190128J61'], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('admin', 'PATCH', "/fiscal-issuers/{$issuer['id']}", ['legalName' => 'EKU SA', 'globalPeriodicity' => 'BIMONTHLY'])->assertStatus(400);
        $this->as('admin', 'PATCH', "/fiscal-issuers/{$issuer['id']}", ['legalName' => 'EKU SA', 'globalPeriodicity' => 'BIMONTHLY'], ['If-Match' => '"1"'])
            ->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.legalName', 'EKU SA')->assertJsonPath('data.globalPeriodicity', 'BIMONTHLY');
        $this->as('admin', 'PATCH', "/fiscal-issuers/{$issuer['id']}", ['legalName' => 'Otra'], ['If-Match' => '"1"'])
            ->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $audit = (array) DB::connection('mongodb')->table('audit_log')->where('action', 'fiscal_issuer.updated')->first();
        $this->assertSame('ESCUELA KEMPER URGATE', ((array) $audit['changes']['before'])['legalName']);
    }

    public function test_csd_y_activacion(): void
    {
        $issuer = $this->create();
        $id = $issuer['id'];
        // Sin CSD no se activa.
        $this->as('admin', 'POST', "/fiscal-issuers/$id/status", ['status' => 'ACTIVE', 'reason' => 'Listo'], ['If-Match' => '"1"'])
            ->assertStatus(409)->assertJsonPath('error.code', 'FISCAL_ISSUER_NOT_CONFIGURED');

        $this->uploadCsd($id, '"1"', password: 'mala')->assertStatus(409)->assertJsonPath('error.code', 'CSD_INVALID');
        $this->uploadCsd($id, '"1"', cer: 'XIA190128J61.cer')->assertStatus(409)->assertJsonPath('error.code', 'CSD_INVALID');
        $this->uploadCsd($id, '"1"', password: null)->assertStatus(422)->assertJsonValidationErrors('password', 'error.details.fields');
        $this->uploadCsd($id, '"1"', extra: ['rfc' => 'X'])->assertStatus(422);
        $this->uploadCsd($id, '"9"')->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $response = $this->uploadCsd($id, '"1"')->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.csd.serial', '30001000000500003416');
        $this->assertStringEndsWith('Z', $response->json('data.csd.validUntil'));
        // Nada del contenido ni de la contraseña queda en la base, la auditoría o la respuesta.
        $raw = json_encode([DB::connection('mongodb')->table('fiscal_issuers')->get(), DB::connection('mongodb')->table('audit_log')->get(), DB::connection('mongodb')->table('outbox_events')->get()]);
        $this->assertStringNotContainsString('12345678a', $raw.$response->getContent());
        $this->assertStringNotContainsString(base64_encode(substr($this->fixture('EKU9003173C9.key'), 0, 30)), $raw);

        $this->as('admin', 'POST', "/fiscal-issuers/$id/status", ['status' => 'ACTIVE', 'reason' => 'Listo'], ['If-Match' => '"2"'])
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        // Repetir no cambia la versión.
        $this->as('admin', 'POST', "/fiscal-issuers/$id/status", ['status' => 'ACTIVE', 'reason' => 'Listo'], ['If-Match' => '"3"'])->assertOk()->assertHeader('ETag', '"3"');
        $this->assertSame('Listo', DB::connection('mongodb')->table('audit_log')->where('action', 'fiscal_issuer.status_changed')->first()->reason);
    }

    public function test_un_csd_vencido_se_rechaza(): void
    {
        $id = $this->create()['id'];
        Carbon::setTestNow(now()->addYears(11));
        try {
            $this->staff['admin'] = $this->staffMember('admin2@ejemplo.mx', $this->branch, 'ADMIN');
            $this->uploadCsd($id, '"1"')->assertStatus(409)->assertJsonPath('error.code', 'CSD_INVALID');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_el_pac_rechaza_y_no_cambia_nada(): void
    {
        $id = $this->create()['id'];
        $this->app->instance(Pac::class, new class implements Pac
        {
            public function registerCsd(string $rfc, string $certificate, string $privateKey, string $password): CsdInfo
            {
                throw new PacFailure('301', 'RFC no inscrito');
            }

            public function stamp(StampRequest $request): StampResult
            {
                throw new PacFailure('301');
            }

            public function find(string $reference): ?StampResult
            {
                return null;
            }
        });
        $this->uploadCsd($id, '"1"')->assertStatus(409)->assertJsonPath('error.code', 'FISCAL_OPERATION_FAILED')->assertJsonPath('error.details.providerCode', '301');
        $this->as('admin', 'GET', "/fiscal-issuers/$id")->assertJsonPath('data.version', 1)->assertJsonPath('data.csd', null);
    }

    public function test_asignar_a_la_sucursal(): void
    {
        $id = $this->create()['id'];
        $branchVersion = fn () => '"'.$this->branch->fresh()->version.'"';
        $this->as('admin', 'PUT', 'fiscal-issuer', ['fiscalIssuerId' => $id], ['If-Match' => $branchVersion()])
            ->assertStatus(409)->assertJsonPath('error.code', 'FISCAL_ISSUER_NOT_CONFIGURED');
        $this->uploadCsd($id, '"1"')->assertOk();
        $this->as('admin', 'POST', "/fiscal-issuers/$id/status", ['status' => 'ACTIVE', 'reason' => 'Listo'], ['If-Match' => '"2"'])->assertOk();

        $this->as('gerente', 'PUT', 'fiscal-issuer', ['fiscalIssuerId' => $id], ['If-Match' => $branchVersion()])->assertStatus(403);
        $this->as('admin', 'PUT', 'fiscal-issuer', ['fiscalIssuerId' => $id], ['If-Match' => '"99"'])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->as('admin', 'PUT', 'fiscal-issuer', ['fiscalIssuerId' => fake()->uuid()], ['If-Match' => $branchVersion()])
            ->assertStatus(409)->assertJsonPath('error.code', 'FISCAL_ISSUER_NOT_CONFIGURED');
        $this->as('admin', 'PUT', 'fiscal-issuer', ['fiscalIssuerId' => $id], ['If-Match' => $branchVersion()])->assertOk()->assertJsonPath('data.fiscalIssuerId', $id);
        $this->as('mesero', 'GET', '')->assertJsonPath('data.fiscalIssuerId', $id);
        $audit = (array) DB::connection('mongodb')->table('audit_log')->where('action', 'branch.fiscal_issuer_changed')->first();
        $this->assertSame(['before' => null, 'after' => $id], (array) $audit['changes']);

        // Un emisor inactivo no se asigna.
        $this->as('admin', 'POST', "/fiscal-issuers/$id/status", ['status' => 'INACTIVE', 'reason' => 'Cambio de razón social'], ['If-Match' => '"3"'])->assertOk();
        $this->as('admin', 'PUT', 'fiscal-issuer', ['fiscalIssuerId' => $id], ['If-Match' => $branchVersion()])
            ->assertStatus(409)->assertJsonPath('error.code', 'FISCAL_ISSUER_NOT_CONFIGURED');
    }
}
