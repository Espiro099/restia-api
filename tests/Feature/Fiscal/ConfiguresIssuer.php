<?php

namespace Tests\Feature\Fiscal;

use Illuminate\Testing\TestResponse;

/** Emisor con CSD de prueba (tests/Fixtures/csd) para las pruebas fiscales. Requiere `staff['admin']`. */
trait ConfiguresIssuer
{
    protected array $issuerBody = ['rfc' => 'EKU9003173C9', 'legalName' => 'ESCUELA KEMPER URGATE', 'taxRegime' => '601', 'postalCode' => '44100'];

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/csd/$name"));
    }

    /** PUT multipart como lo manda el navegador, con los archivos en el cuerpo. */
    protected function uploadCsd(string $id, string $version, string $cer = 'EKU9003173C9.cer', ?string $password = '12345678a', array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $boundary = 'restia'.bin2hex(random_bytes(6));
        $part = fn (string $name, string $content, ?string $file = null) => "--$boundary\r\nContent-Disposition: form-data; name=\"$name\""
            .($file ? "; filename=\"$file\"\r\nContent-Type: application/octet-stream" : '')."\r\n\r\n$content\r\n";
        $body = $part('certificate', $this->fixture($cer), $cer).$part('privateKey', $this->fixture('EKU9003173C9.key'), 'llave.key')
            .($password === null ? '' : $part('password', $password));
        foreach ($extra as $name => $value) {
            $body .= $part($name, $value);
        }
        $body .= "--$boundary--\r\n";

        return $this->call('PUT', "/api/v1/fiscal-issuers/$id/csd", [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->staff['admin']['access'], 'HTTP_IF_MATCH' => $version, 'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => "multipart/form-data; boundary=$boundary",
        ], $body);
    }

    /** Emisor activo, con CSD y asignado a la sucursal. Devuelve su id. */
    protected function readyIssuer(): string
    {
        $id = $this->as('admin', 'POST', '/fiscal-issuers', $this->issuerBody)->assertCreated()->json('data.id');
        $this->uploadCsd($id, '"1"')->assertOk();
        $this->as('admin', 'POST', "/fiscal-issuers/$id/status", ['status' => 'ACTIVE', 'reason' => 'Listo'], ['If-Match' => '"2"'])->assertOk();
        $this->as('admin', 'PUT', 'fiscal-issuer', ['fiscalIssuerId' => $id], ['If-Match' => '"'.$this->branch->fresh()->version.'"'])->assertOk();

        return $id;
    }
}
