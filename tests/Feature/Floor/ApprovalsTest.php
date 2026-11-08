<?php

namespace Tests\Feature\Floor;

use App\Models\OrderItemUnit;
use Illuminate\Support\Facades\DB;

/** D-37: un gerente autoriza la anulación en el teléfono del mesero; O-3: VOID desde PREPARING y READY. */
class ApprovalsTest extends FloorTestCase
{
    /** Las dos aguas en preparación y los tacos listos. @return array{0: list<array>, 1: list<array>} */
    private function started(): array
    {
        $order = $this->as('mesero', 'POST', 'orders', $this->round($this->openSession(1)), $this->key())->json('data');
        $water = $this->unitsOf($order);
        $tacos = $this->unitsOf($order, 1);
        $this->transition('cocina', 'START', [...$water, ...$tacos])->assertOk();
        $this->transition('cocina', 'READY', $this->bump($tacos))->assertOk();

        return [$this->bump($water), $this->bump($this->bump($tacos))];
    }

    private function approval(array $units, string $reason = 'Se cayó el plato', string $password = self::PASSWORD, string $email = 'gerente@ejemplo.mx')
    {
        return $this->as('mesero', 'POST', 'approvals', [
            'approverEmail' => $email,
            'approverPassword' => $password,
            'action' => 'ORDER_UNIT_VOID',
            'resourceIds' => array_column($units, 'id'),
            'reason' => $reason,
        ]);
    }

    public function test_mesero_anula_con_la_autorizacion_del_gerente(): void
    {
        [$water] = $this->started();
        $r = $this->approval($water)->assertCreated()->assertNoStore()->assertJsonPath('data.expiresIn', 120);
        $token = $r->json('data.approvalToken');

        $this->transition('mesero', 'VOID', $water, 'Se cayó el plato', ['X-Approval-Token' => $token])->assertOk()
            ->assertJsonPath('data.0.status', 'VOIDED')
            ->assertJsonPath('data.1.reason', 'Se cayó el plato');
        $audit = DB::connection('mongodb')->table('audit_log')->where('action', 'order_unit.voided')->first();
        $this->assertSame((string) $this->staff['gerente']['user']->getKey(), ((array) $audit)['changes']['approvedBy']);

        // Ni la contraseña ni el token quedan en la auditoría.
        $all = json_encode(DB::connection('mongodb')->table('audit_log')->get());
        $this->assertStringNotContainsString(self::PASSWORD, $all);
        $this->assertStringNotContainsString($token, $all);

        // Un solo uso.
        $this->transition('mesero', 'VOID', $this->bump($water), 'Se cayó el plato', ['X-Approval-Token' => $token])->assertStatus(409);
    }

    public function test_el_token_queda_ligado_a_unidades_y_motivo(): void
    {
        [$water, $tacos] = $this->started();
        $token = $this->approval($water)->json('data.approvalToken');

        $this->transition('mesero', 'VOID', [$water[0]], 'Se cayó el plato', ['X-Approval-Token' => $token])->assertStatus(409)->assertJsonPath('error.code', 'APPROVAL_INVALID');
        $this->transition('mesero', 'VOID', $water, 'Otro motivo', ['X-Approval-Token' => $token])->assertStatus(409)->assertJsonPath('error.code', 'APPROVAL_INVALID');
        $this->transition('mesero', 'VOID', $tacos, 'Se cayó el plato', ['X-Approval-Token' => $token])->assertStatus(409)->assertJsonPath('error.code', 'APPROVAL_INVALID');
        $this->transition('mesero', 'VOID', $water, 'Se cayó el plato', ['X-Approval-Token' => 'inventado'])->assertStatus(409)->assertJsonPath('error.code', 'APPROVAL_INVALID');
        // Ninguno de esos intentos lo gastó; el orden de las unidades no importa.
        $this->transition('mesero', 'VOID', array_reverse($water), 'Se cayó el plato', ['X-Approval-Token' => $token])->assertOk();
    }

    public function test_si_la_operacion_falla_el_token_sigue_vigente_hasta_vencer(): void
    {
        [$water] = $this->started();
        $token = $this->approval($water)->json('data.approvalToken');
        $stale = array_map(fn ($u) => ['id' => $u['id'], 'version' => 1], $water);
        $this->transition('mesero', 'VOID', $stale, 'Se cayó el plato', ['X-Approval-Token' => $token])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->transition('mesero', 'VOID', $water, 'Se cayó el plato', ['X-Approval-Token' => $token])->assertOk();

        [$tacos] = [$this->unitsOf($this->as('mesero', 'GET', 'orders')->json('data.0'), 1)];
        $late = $this->approval($tacos)->json('data.approvalToken');
        $this->travel(121)->seconds();
        $this->transition('mesero', 'VOID', $tacos, 'Se cayó el plato', ['X-Approval-Token' => $late])->assertStatus(409)->assertJsonPath('error.code', 'APPROVAL_INVALID');
        $this->assertSame(OrderItemUnit::READY, OrderItemUnit::query()->where('uuid', $tacos[0]['id'])->first()->status);
    }

    public function test_credenciales_malas_o_sin_permiso_dan_403_sin_tocar_la_sesion(): void
    {
        [$water] = $this->started();
        $this->approval($water, password: 'equivocada')->assertStatus(403)->assertJsonPath('error.code', 'APPROVAL_DENIED');
        $this->approval($water, email: 'nadie@ejemplo.mx')->assertStatus(403)->assertJsonPath('error.code', 'APPROVAL_DENIED');
        // Un mesero no puede autorizar, aunque escriba bien su contraseña.
        $this->approval($water, email: 'mesero@ejemplo.mx')->assertStatus(403)->assertJsonPath('error.code', 'APPROVAL_DENIED');
        $this->as('mesero', 'GET', '/auth/me')->assertOk();
        $this->assertSame(3, $this->auditCount('approval.denied'));
        $this->assertSame(0, DB::connection('mongodb')->table('approval_grants')->count());
    }

    public function test_limite_de_intentos_por_aprobador(): void
    {
        [$water] = $this->started();
        for ($i = 0; $i < 5; $i++) {
            $this->approval($water, password: 'equivocada')->assertStatus(403);
        }
        $this->approval($water)->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS')->assertHeader('Retry-After');
    }

    public function test_gerente_anula_directo_y_tambien_desde_listo(): void
    {
        [$water, $tacos] = $this->started();
        $this->transition('gerente', 'VOID', [...$water, ...$tacos], 'Cliente se fue')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(0, DB::connection('mongodb')->table('approval_grants')->count());
        $this->as('mesero', 'GET', 'orders')->assertJsonPath('data.0.status', 'CANCELLED');
        // Lo anulado ya no se puede anular otra vez.
        $this->transition('gerente', 'VOID', $this->bump($water), 'Otra vez')->assertStatus(409)->assertJsonPath('error.code', 'ORDER_UNIT_INVALID_STATE');
    }
}
