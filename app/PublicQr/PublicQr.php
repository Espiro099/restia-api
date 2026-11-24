<?php

namespace App\PublicQr;

use App\Exceptions\DomainConflict;
use App\Floor\Menu;
use App\Models\Branch;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Página pública del QR (Decisiones_Public_QR): menú y presencia, nada más (QR-1, QR-2). El token
 * identifica la mesa, no la sesión (QR-3), y nunca se devuelven ids de sucursal, mesa ni sesión (QR-6).
 */
final class PublicQr
{
    /** 256 bits en base64url, sin relleno: 43 caracteres. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Mesa y sucursal de un token vigente: el actual, o el anterior dentro de su gracia (QR-4).
     * Una mesa INACTIVE o una sucursal inactiva no tienen QR válido.
     *
     * @return array{0: RestaurantTable, 1: Branch}|null
     */
    public static function resolve(string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        $table = RestaurantTable::query()->where('qr_token', $token)->first()
            ?? RestaurantTable::query()->where('previous_qr_token', $token)->where('previous_qr_expires_at', '>', now())->first();
        if (! $table || $table->status === RestaurantTable::INACTIVE) {
            return null;
        }
        $branch = Branch::query()->find((string) $table->branch_id);

        return $branch?->isActive() ? [$table, $branch] : null;
    }

    public static function context(RestaurantTable $table, Branch $branch): array
    {
        $address = self::address($branch);

        return [
            'restaurantName' => $branch->name,
            'tableNumber' => (int) $table->number,
            'currency' => $branch->currency ?: 'MXN',
            'timezone' => $branch->timezone ?: 'America/Mexico_City',
            // QR-13: lo que el cliente necesita para volver o llegar; el enlace abre Google Maps sin llave.
            'phone' => $branch->phone ?: null,
            'address' => $address,
            'mapsUrl' => $address ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(self::addressLine($address)) : null,
        ];
    }

    /** Dirección pública de la sucursal, sin el país (todas son de México), o null si no tiene. */
    private static function address(Branch $branch): ?array
    {
        $a = $branch->address ? (array) json_decode(json_encode($branch->address), true) : null;
        if (! $a || empty($a['line1'])) {
            return null;
        }

        return [
            'line1' => $a['line1'],
            'line2' => $a['line2'] ?? null,
            'city' => $a['city'] ?? null,
            'state' => $a['state'] ?? null,
            'postalCode' => $a['postalCode'] ?? null,
        ];
    }

    /** "Av. Juárez 120, Centro, 44100 Guadalajara, Jalisco" */
    public static function addressLine(array $a): string
    {
        $place = trim(($a['postalCode'] ?? '').' '.($a['city'] ?? ''));

        return implode(', ', array_filter([$a['line1'], $a['line2'] ?? null, $place ?: null, $a['state'] ?? null]));
    }

    /**
     * QR-7: el menú interno sin códigos ni la sucursal. Los agotados se ven marcados. QR-15: con la descripción
     * del platillo (la escribe el gerente para el cliente) y la recomendación del día (M-13).
     */
    public static function menu(Branch $branch): array
    {
        $menu = Menu::build($branch);
        $fields = ['id', 'name', 'description', 'category', 'displayOrder', 'recommended', 'price', 'availability', 'modifierIds', 'imageUrl'];

        return [
            'menuVersion' => $menu['menuVersion'],
            'products' => array_map(fn ($p) => array_intersect_key($p, array_flip($fields)), $menu['products']),
            'modifiers' => $menu['modifiers'],
        ];
    }

    /**
     * QR-8: un documento por mesa que vence solo; sin datos del visitante. Una mesa en mantenimiento
     * no registra nada. Sin transacción ni auditoría: no es una acción de negocio.
     */
    public static function presence(RestaurantTable $table): void
    {
        if ($table->status !== RestaurantTable::ACTIVE) {
            return;
        }
        $now = now();
        DB::connection('mongodb')->table('table_presence_signals')->where('branch_id', new ObjectId((string) $table->branch_id))
            ->where('table_id', new ObjectId($table->getKey()))
            ->update([
                'last_seen_at' => new UTCDateTime($now),
                'expires_at' => new UTCDateTime($now->copy()->addMinutes(config('restia.public.presence_minutes'))),
            ], ['upsert' => true]);
    }

    /** Mesas con una señal vigente, para el estado POSIBLE_OCUPACION. @return array<string, true> */
    public static function signaled(ObjectId $branchId): array
    {
        return DB::connection('mongodb')->table('table_presence_signals')->where('branch_id', $branchId)
            ->where('expires_at', '>', new UTCDateTime(now()))->get(['table_id'])
            ->mapWithKeys(fn ($s) => [(string) ((array) $s)['table_id'] => true])->all();
    }

    // --- Personal (QR-12) ---

    /** Token, URL para imprimir y vencimiento del anterior. */
    public static function staffView(RestaurantTable $table): array
    {
        $previous = $table->previous_qr_expires_at && $table->previous_qr_expires_at->isFuture() ? $table->previous_qr_expires_at : null;

        return [
            'tableId' => $table->uuid,
            'token' => $table->qr_token,
            'url' => rtrim(config('restia.public.url'), '/').'/m#'.$table->qr_token,
            'previousTokenExpiresAt' => $previous ? Carbon::instance($previous)->utc()->format('Y-m-d\TH:i:s\Z') : null,
            'version' => (int) $table->version,
        ];
    }

    /** Mesas creadas antes de Public QR: reciben su token la primera vez que se consultan. */
    public static function ensureToken(RestaurantTable $table): RestaurantTable
    {
        if (! $table->qr_token) {
            RestaurantTable::query()->where('_id', new ObjectId($table->getKey()))->whereNull('qr_token')->update(['qr_token' => self::newToken()]);
            $table->refresh();
        }

        return $table;
    }

    /**
     * QR-4: el token actual pasa a ser el anterior por 7 días. Si ya había un anterior en gracia, deja
     * de servir de inmediato. El token nunca va a la auditoría.
     */
    public static function rotate(Branch $branch, User $actor, RestaurantTable $table, int $version, string $reason): RestaurantTable
    {
        $table = self::ensureToken($table);
        $branchId = new ObjectId($branch->getKey());

        Mongo::transaction(function () use ($table, $version, $reason, $branchId, $actor) {
            Branch::query()->where('_id', $branchId)->increment('operations_epoch');
            if (! Branch::query()->find($branchId)?->isActive()) {
                throw new DomainConflict('BRANCH_INACTIVE');
            }
            $fresh = RestaurantTable::query()->find($table->getKey());
            $hadPrevious = $fresh->previous_qr_expires_at && $fresh->previous_qr_expires_at->isFuture();
            $modified = RestaurantTable::query()->where('_id', new ObjectId($fresh->getKey()))->where('version', $version)->increment('version', 1, [
                'qr_token' => self::newToken(),
                'previous_qr_token' => $fresh->qr_token,
                'previous_qr_expires_at' => new UTCDateTime(now()->addDays(config('restia.public.previous_token_days'))),
                'updated_at' => new UTCDateTime,
            ]);
            if ($modified === 0) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record('table.qr_rotated', 'table', $fresh->getKey(), $branchId, new ObjectId($actor->getKey()), reason: $reason,
                changes: ['table' => (int) $fresh->number, 'previousTokenRevoked' => $hadPrevious]);
            Outbox::publish('table.qr_rotated', 'table', $fresh->getKey(), $branchId, ['tableId' => $fresh->uuid]);
        });

        return $table->refresh();
    }
}
