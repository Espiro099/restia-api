<?php

namespace App\Fiscal;

use App\Exceptions\DomainConflict;
use App\Fiscal\Pac\CsdRejected;
use App\Fiscal\Pac\Pac;
use App\Fiscal\Pac\PacFailure;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\FiscalIssuer;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Closure;
use MongoDB\BSON\ObjectId;
use Throwable;

/**
 * Emisores fiscales (F-12, F-13). Un emisor nace inactivo: para activarlo necesita un CSD vigente
 * registrado en el PAC. El RFC no cambia; un RFC distinto es otro emisor.
 */
final class Issuers
{
    private const FIELDS = ['legalName' => 'legal_name', 'taxRegime' => 'tax_regime', 'postalCode' => 'postal_code', 'globalPeriodicity' => 'global_periodicity'];

    public static function present(FiscalIssuer $i): array
    {
        return [
            'id' => $i->uuid,
            'rfc' => $i->rfc,
            'legalName' => $i->legal_name,
            'taxRegime' => $i->tax_regime,
            'postalCode' => $i->postal_code,
            'globalPeriodicity' => $i->global_periodicity,
            // Nunca el certificado, la llave ni la contraseña: Restia no los tiene (F-12).
            'csd' => $i->csd_provider_ref ? ['serial' => $i->csd_serial, 'validUntil' => MobileAuth::time($i->csd_valid_until)] : null,
            'status' => $i->status,
            'version' => (int) $i->version,
        ];
    }

    public static function create(User $actor, array $in): FiscalIssuer
    {
        try {
            return Mongo::transaction(function () use ($actor, $in) {
                $issuer = FiscalIssuer::query()->create([
                    'rfc' => $in['rfc'], 'legal_name' => $in['legalName'], 'tax_regime' => $in['taxRegime'], 'postal_code' => $in['postalCode'],
                    // F-6: mensual por omisión; hasta cerrar el mes se puede facturar sin cancelar la global.
                    'global_periodicity' => $in['globalPeriodicity'] ?? 'MONTHLY',
                    'status' => FiscalIssuer::INACTIVE,
                ]);
                Audit::record('fiscal_issuer.created', 'fiscal_issuer', $issuer->getKey(), null, new ObjectId($actor->getKey()),
                    changes: ['rfc' => $in['rfc'], 'legalName' => $in['legalName']]);
                Outbox::publish('fiscal_issuer.created', 'fiscal_issuer', $issuer->getKey(), null, ['fiscalIssuerId' => $issuer->uuid]);

                return $issuer;
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('FISCAL_ISSUER_RFC_TAKEN') : $e;
        }
    }

    public static function update(User $actor, FiscalIssuer $issuer, int $version, array $in): FiscalIssuer
    {
        return self::change($actor, $issuer, $version, function (FiscalIssuer $fresh) use ($in) {
            $changes = [];
            $before = [];
            foreach (self::FIELDS as $field => $column) {
                if (array_key_exists($field, $in) && $in[$field] !== $fresh->{$column}) {
                    $changes[$column] = $in[$field];
                    $before[$field] = $fresh->{$column};
                }
            }

            return [$changes, 'fiscal_issuer.updated', ['before' => $before, 'after' => array_intersect_key($in, $before)], null];
        });
    }

    public static function setStatus(User $actor, FiscalIssuer $issuer, int $version, string $status, string $reason): FiscalIssuer
    {
        return self::change($actor, $issuer, $version, function (FiscalIssuer $fresh) use ($status, $reason) {
            if ($fresh->status === $status) {
                return [[], null, [], null];
            }
            if ($status === FiscalIssuer::ACTIVE && ! $fresh->hasValidCsd()) {
                throw new DomainConflict('FISCAL_ISSUER_NOT_CONFIGURED');
            }

            return [['status' => $status], 'fiscal_issuer.status_changed', ['before' => $fresh->status, 'after' => $status], $reason];
        });
    }

    /**
     * F-12: el CSD va al PAC fuera de la transacción y aquí solo queda lo que el PAC devuelve. La versión
     * se revisa antes, para no molestar al PAC con una petición que de todos modos chocaría, y otra vez al guardar.
     */
    public static function registerCsd(User $actor, FiscalIssuer $issuer, int $version, string $certificate, string $privateKey, string $password): FiscalIssuer
    {
        if ((int) $issuer->version !== $version) {
            throw new DomainConflict('VERSION_CONFLICT');
        }
        try {
            $csd = app(Pac::class)->registerCsd($issuer->rfc, $certificate, $privateKey, $password);
        } catch (CsdRejected) {
            throw new DomainConflict('CSD_INVALID');
        } catch (PacFailure $e) {
            throw new DomainConflict('FISCAL_OPERATION_FAILED', ['providerCode' => $e->providerCode]);
        }

        return self::change($actor, $issuer, $version, fn () => [
            ['csd_provider_ref' => $csd->providerRef, 'csd_serial' => $csd->serial, 'csd_valid_until' => $csd->validUntil],
            'fiscal_issuer.csd_updated',
            ['serial' => $csd->serial, 'validUntil' => $csd->validUntil->utc()->format('Y-m-d\TH:i:s\Z')],
            null,
        ]);
    }

    /** Solo afecta a facturas nuevas: las existentes conservan su emisor. */
    public static function assignToBranch(User $actor, Branch $branch, int $version, string $issuerUuid): Branch
    {
        $branchId = new ObjectId($branch->getKey());
        Mongo::transaction(function () use ($actor, $branchId, $version, $issuerUuid) {
            $issuer = FiscalIssuer::query()->where('uuid', strtolower($issuerUuid))->first();
            if (! $issuer?->canIssue()) {
                throw new DomainConflict('FISCAL_ISSUER_NOT_CONFIGURED');
            }
            $fresh = Branch::query()->find($branchId);
            if ((int) $fresh->version !== $version) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            $issuerId = new ObjectId($issuer->getKey());
            if ((string) $fresh->fiscal_issuer_id === (string) $issuerId) {
                return;
            }
            $before = $fresh->fiscal_issuer_id ? FiscalIssuer::query()->find((string) $fresh->fiscal_issuer_id)?->uuid : null;
            if (! $fresh->updateIfVersion($version, ['fiscal_issuer_id' => $issuerId])) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record('branch.fiscal_issuer_changed', 'branch', $branchId, $branchId, new ObjectId($actor->getKey()),
                changes: ['before' => $before, 'after' => $issuer->uuid]);
            Outbox::publish('branch.fiscal_issuer_changed', 'branch', $branchId, $branchId, ['branchId' => $fresh->uuid, 'fiscalIssuerId' => $issuer->uuid]);
        });

        return $branch->refresh();
    }

    /** Uuid del emisor de la sucursal, si tiene. */
    public static function uuidOf(Branch $branch): ?string
    {
        return $branch->fiscal_issuer_id ? FiscalIssuer::query()->find((string) $branch->fiscal_issuer_id)?->uuid : null;
    }

    /** If-Match, auditoría y outbox en una transacción. $work devuelve [cambios, evento, auditoría, motivo]; sin evento no hay cambios. */
    private static function change(User $actor, FiscalIssuer $issuer, int $version, Closure $work): FiscalIssuer
    {
        $id = new ObjectId($issuer->getKey());
        Mongo::transaction(function () use ($actor, $id, $version, $work) {
            $fresh = FiscalIssuer::query()->find($id);
            if ((int) $fresh->version !== $version) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            [$changes, $event, $audit, $reason] = $work($fresh);
            if (! $event || ! $changes) {
                return;
            }
            if (! $fresh->updateIfVersion($version, $changes)) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record($event, 'fiscal_issuer', $id, null, new ObjectId($actor->getKey()), reason: $reason, changes: $audit);
            Outbox::publish($event, 'fiscal_issuer', $id, null, ['fiscalIssuerId' => $fresh->uuid]);
        });

        return $issuer->refresh();
    }
}
