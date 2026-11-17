<?php

namespace App\Console\Commands;

use App\Fiscal\Pac\PacFailure;
use App\Fiscal\Pac\PacUnreachable;
use App\Fiscal\Pac\StampRequest;
use App\Fiscal\Pac\SwSapienPac;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Prueba de punta a punta contra el sandbox de SW Sapien: autentica, carga el CSD de pruebas y timbra un
 * comprobante de ejemplo. Usa SW_USER/SW_PASSWORD (o SW_TOKEN) del .env y nunca los imprime. Los CSD de
 * prueba de SW se descargan de developers.sw.com.mx ("¿Dónde encuentro CSD de pruebas vigentes?").
 */
class SwSandbox extends Command
{
    protected $signature = 'restia:sw-sandbox
        {--cer= : Ruta del .cer de pruebas (p. ej. EKU9003173C9.cer)}
        {--key= : Ruta del .key de pruebas}
        {--rfc=EKU9003173C9 : RFC del CSD de pruebas}
        {--name=ESCUELA KEMPER URGATE : Razón social del emisor de pruebas}
        {--regime=601 : Régimen fiscal del emisor}
        {--cp=42501 : Código postal del emisor}';

    protected $description = 'Timbra un CFDI de prueba en el sandbox de SW Sapien (solo fuera de producción)';

    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('No se corre en producción.');

            return self::FAILURE;
        }
        $config = config('services.sw');
        if (! str_contains((string) $config['url'], '.test.')) {
            $this->error('SW_URL no es el sandbox (services.test.sw.com.mx).');

            return self::FAILURE;
        }
        try {
            $pac = new SwSapienPac($config);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            if ($this->option('cer') && $this->option('key')) {
                $password = (string) $this->secret('Contraseña de la llave de pruebas');
                $csd = $pac->registerCsd($this->option('rfc'), (string) file_get_contents($this->option('cer')), (string) file_get_contents($this->option('key')), $password);
                $this->info("CSD cargado en SW: certificado {$csd->serial}, vigente hasta {$csd->validUntil->toDateString()}");
            }

            $request = new StampRequest(
                reference: 'restia-sandbox-'.Str::lower(Str::random(10)),
                type: 'INGRESO',
                issuer: ['rfc' => $this->option('rfc'), 'legalName' => $this->option('name'), 'taxRegime' => $this->option('regime'), 'postalCode' => $this->option('cp'), 'csdRef' => ''],
                receiver: null,
                series: 'PRUEBA', folio: (string) random_int(1, 99999), paymentMethod: 'PUE', paymentForm: '01', currency: 'MXN',
                lines: [['description' => 'Consumo de alimentos', 'satProductCode' => '90101501', 'satUnitCode' => 'E48', 'quantity' => '1',
                    'unitValue' => '100.000000', 'amount' => '100.00', 'taxObject' => '02', 'taxRate' => '0.160000', 'taxAmount' => '16.00']],
                subtotal: '100.00', taxTotal: '16.00', total: '116.00',
                issuedAt: CarbonImmutable::now()->subMinutes(5),
                global: ['periodicity' => '01', 'months' => CarbonImmutable::now('America/Mexico_City')->format('m'), 'year' => CarbonImmutable::now('America/Mexico_City')->format('Y')],
            );
            $result = $pac->stamp($request);
        } catch (PacFailure $e) {
            $this->error("SW rechazó la operación ({$e->providerCode}): {$e->getMessage()}");

            return self::FAILURE;
        } catch (PacUnreachable $e) {
            $this->error('Sin respuesta clara de SW: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Timbrado en el sandbox de SW Sapien.');
        $this->table(['Referencia', 'Folio fiscal (UUID)', 'Fecha de timbrado (UTC)', 'Tamaño del XML'],
            [[$request->reference, $result->fiscalUuid, $result->stampedAt->toIso8601String(), strlen($result->xml).' bytes']]);

        return self::SUCCESS;
    }
}
