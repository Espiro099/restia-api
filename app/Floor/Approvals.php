<?php

namespace App\Floor;

use App\Exceptions\DomainConflict;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Throttle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * D-37: un gerente autoriza en el dispositivo de otro empleado. El token dura 2 minutos, sirve una vez y
 * queda ligado a la acción, las unidades y el motivo. Solo se guarda su hash; ni el token ni la contraseña
 * llegan a la auditoría ni a los logs.
 */
final class Approvals
{
    /** Permiso que debe tener el aprobador para cada acción. */
    public const PERMISSIONS = [
        'ORDER_UNIT_VOID' => 'order_units.void',
        'INVENTORY_OVERRIDE' => 'inventory.override',
        'CHECK_REOPEN' => 'checks.reopen',
        'CHECK_ADJUSTMENT' => 'checks.adjust',
    ];

    /**
     * @param  array{approverEmail: string, approverPassword: string, action: string, resourceIds: list<string>, reason: string}  $in
     * @return array{approvalToken: string, expiresIn: int}
     */
    public static function grant(Branch $branch, User $requester, array $in, string $ip): array
    {
        $email = User::normalizeEmail($in['approverEmail']);
        Throttle::hit('approvals|'.$email.'|'.$ip, config('restia.floor.approvals_per_approver_ip'));
        $branchId = new ObjectId($branch->getKey());
        $requesterId = new ObjectId($requester->getKey());

        $approver = User::query()->where('normalized_email', $email)->first();
        // Siempre se compara un hash, exista o no el usuario, para no delatarlo por el tiempo.
        $valid = Hash::check($in['approverPassword'], $approver?->password_hash ?? MobileAuth::dummyHash());
        $member = $valid && $approver?->isActive() ? Membership::query()->where('user_id', new ObjectId($approver->getKey()))
            ->where('branch_id', $branchId)->where('status', Membership::ACTIVE)->first() : null;
        if (! $member?->can(self::PERMISSIONS[$in['action']])) {
            // Nunca 401: haría que la app del solicitante renovara o cerrara su propia sesión.
            Audit::record('approval.denied', 'approval', null, $branchId, $requesterId, changes: ['action' => $in['action'], 'resources' => count($in['resourceIds'])]);
            throw new DomainConflict('APPROVAL_DENIED');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $seconds = (int) config('restia.floor.approval_seconds');
        $approverId = new ObjectId($approver->getKey());
        Mongo::transaction(function () use ($token, $seconds, $branchId, $requesterId, $approverId, $in) {
            DB::connection('mongodb')->table('approval_grants')->insert([
                'token_hash' => hash('sha256', $token),
                'branch_id' => $branchId,
                'action' => $in['action'],
                'resource_ids' => self::normalize($in['resourceIds']),
                'requested_by' => $requesterId,
                'approved_by' => $approverId,
                'reason' => $in['reason'],
                'created_at' => new UTCDateTime,
                'expires_at' => new UTCDateTime(now()->addSeconds($seconds)),
                'used_at' => null,
            ]);
            Audit::record('approval.granted', 'approval', null, $branchId, $requesterId, reason: $in['reason'], changes: [
                'action' => $in['action'],
                'approvedBy' => (string) $approverId,
                'resourceIds' => self::normalize($in['resourceIds']),
            ]);
        });

        return ['approvalToken' => $token, 'expiresIn' => $seconds];
    }

    /**
     * Consume el token dentro de la transacción de la operación. Si esa transacción se revierte, el token
     * sigue vigente hasta vencer. Devuelve quién aprobó.
     *
     * @param  list<string>  $resourceIds
     */
    public static function consume(ObjectId $branchId, ?string $token, string $action, array $resourceIds, ?string $reason): ObjectId
    {
        if ($token === null || $token === '') {
            throw new DomainConflict('APPROVAL_INVALID');
        }
        $grants = DB::connection('mongodb')->table('approval_grants');
        $grant = (array) ($grants->where('token_hash', hash('sha256', $token))->first() ?? []);
        $matches = $grant
            && (string) $grant['branch_id'] === (string) $branchId
            && $grant['action'] === $action
            && $grant['used_at'] === null
            && $grant['expires_at']->toDateTime() > now()
            && (array) $grant['resource_ids'] === self::normalize($resourceIds)
            && $grant['reason'] === $reason;
        if (! $matches) {
            throw new DomainConflict('APPROVAL_INVALID');
        }
        $used = DB::connection('mongodb')->table('approval_grants')
            ->where('token_hash', $grant['token_hash'])->whereNull('used_at')
            ->update(['used_at' => new UTCDateTime]);
        if ($used === 0) {
            throw new DomainConflict('APPROVAL_INVALID');
        }

        return $grant['approved_by'];
    }

    /** @param list<string> $ids @return list<string> */
    private static function normalize(array $ids): array
    {
        $ids = array_map('strtolower', $ids);
        sort($ids);

        return $ids;
    }
}
