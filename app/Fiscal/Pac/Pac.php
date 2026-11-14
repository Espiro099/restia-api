<?php

namespace App\Fiscal\Pac;

/**
 * Proveedor autorizado de certificación (F-1). La API no depende del proveedor: un conector por PAC
 * implementa esta interfaz. Nunca se llama dentro de una transacción de MongoDB.
 */
interface Pac
{
    /**
     * F-12: entrega el CSD al PAC. Restia conserva solo lo que devuelve; el contenido nunca se guarda
     * ni se registra.
     *
     * @throws CsdRejected si el certificado, la llave o la contraseña no sirven
     * @throws PacFailure si el PAC rechaza la operación por otro motivo
     */
    public function registerCsd(string $rfc, string $certificate, string $privateKey, string $password): CsdInfo;
}
