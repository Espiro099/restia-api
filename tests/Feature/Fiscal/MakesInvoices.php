<?php

namespace Tests\Feature\Fiscal;

use App\Fiscal\Stamping;
use Illuminate\Testing\TestResponse;

/** Cuentas pagadas y facturas para las pruebas fiscales. Requiere FloorTestCase y ConfiguresIssuer. */
trait MakesInvoices
{
    private array $receiver = ['rfc' => 'XIA190128J61', 'legalName' => 'XENON INDUSTRIAL ARTICLES', 'taxRegime' => '601', 'postalCode' => '76343', 'cfdiUse' => 'G03'];

    /** Cuenta de una ronda de 207.00, cobrada en efectivo. */
    private function paidCheck(int $table = 1): array
    {
        $session = $this->openSession($table);
        $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertCreated();
        $this->payAll($session);

        return $this->as('mesero', 'GET', "checks?sessionId=$session")->json('data.0');
    }

    private function create(string $check, array $receiver = [], array $headers = [], array $extra = []): TestResponse
    {
        return $this->as('mesero', 'POST', 'invoices', ['checkId' => $check, 'receiver' => $receiver + $this->receiver] + $extra, ($headers ?: $this->key()));
    }

    private function v(array $invoice): array
    {
        return ['If-Match' => '"'.$invoice['version'].'"'];
    }

    private function invoice(string $id): array
    {
        return $this->as('mesero', 'GET', "invoices/$id")->assertOk()->json('data');
    }

    /** Timbra un borrador con el proceso interno y devuelve la factura timbrada. */
    private function stamped(array $draft): array
    {
        $this->as('mesero', 'POST', "invoices/{$draft['id']}/stamp", [], $this->v($draft) + $this->key())->assertStatus(202);
        Stamping::run();

        return $this->invoice($draft['id']);
    }
}
