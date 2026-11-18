<?php

namespace App\Notifications;

use App\Floor\TableSessions;
use App\Models\Branch;
use App\Models\Check;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItemUnit;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use App\Models\TableSession;
use App\Support\Floor;
use App\Support\Mongo;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Avisos de la campana, creados desde outbox:
 *
 * - `order_unit.ready`: al responsable de la mesa. Mientras no lo lea, las unidades listas de la
 *   misma ronda se suman al mismo aviso en lugar de crear uno por platillo.
 * - `check.requested` (pedida por alguien, no la que hace el primer pago): al responsable de la mesa
 *   y a quien cierra caja, menos a quien la pidió.
 * - `payment.confirmed|failed|unknown`: a quien registró el pago, si el resultado lo dio otra persona
 *   o el sistema (un PENDING que venció).
 *
 * Como impresión, cada evento se marca en `handled_by` en la misma transacción que sus avisos, así
 * que procesarlo dos veces no los duplica.
 */
final class Notifications
{
    public const CONSUMER = 'notifications';

    private const EVENTS = ['order_unit.ready', 'check.requested', 'payment.confirmed', 'payment.failed', 'payment.unknown'];

    private const KEEP_DAYS = 30;

    private const FRESH_MINUTES = 30;

    /** @return int eventos atendidos */
    public static function run(int $limit = 200): int
    {
        $events = DB::connection('mongodb')->table('outbox_events')->whereIn('type', self::EVENTS)->where('handled_by', '!=', self::CONSUMER)
            ->orderBy('created_at')->orderBy('_id')->limit($limit)->get();
        foreach ($events as $event) {
            $event = (array) $event;
            // Un aviso de hace más de media hora ya no sirve: al instalar esto no se avisa el historial.
            $stale = $event['created_at'] < now()->subMinutes(self::FRESH_MINUTES);
            Mongo::transaction(function () use ($event, $stale) {
                match (true) {
                    $stale => null,
                    $event['type'] === 'order_unit.ready' => self::orderReady($event),
                    $event['type'] === 'check.requested' => self::checkRequested($event),
                    default => self::paymentResult($event),
                };
                DB::connection('mongodb')->table('outbox_events')->where('id', $event['id'])
                    ->update(['$addToSet' => ['handled_by' => self::CONSUMER]]);
            });
        }

        return count($events);
    }

    /** @return array<string, mixed> */
    public static function present(Notification $n): array
    {
        return [
            'id' => $n->uuid,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'tableNumber' => $n->table_number,
            'sessionId' => $n->session_id ? TableSession::query()->find((string) $n->session_id)?->uuid : null,
            'checkId' => $n->check_id ? Check::query()->find((string) $n->check_id)?->uuid : null,
            'count' => (int) ($n->count ?: 1),
            'readAt' => TableSessions::time($n->read_at),
            'createdAt' => TableSessions::time($n->created_at),
            'updatedAt' => TableSessions::time($n->updated_at),
        ];
    }

    private static function orderReady(array $event): void
    {
        $unit = OrderItemUnit::query()->find((string) $event['source_id']);
        $order = $unit ? Order::query()->find((string) $unit->order_id) : null;
        $session = $order ? TableSession::query()->find((string) $order->session_id) : null;
        if (! $session || ! $session->responsible_user_id || $session->status !== TableSession::ACTIVE) {
            return;
        }
        $recipient = (string) $session->responsible_user_id;
        $table = self::tableNumber($session);
        $group = 'ORDER_READY:'.$order->getKey().':'.$recipient;
        $open = Notification::query()->where('group_key', $group)->whereNull('read_at')->first();
        $count = $open ? (int) $open->count + 1 : 1;
        $fields = [
            'title' => "Mesa $table · orden lista",
            'body' => $count === 1 ? '1 platillo listo para servir.' : "$count platillos listos para servir.",
            'count' => $count,
        ];
        if ($open) {
            $open->update($fields);

            return;
        }
        self::create((string) $session->branch_id, $recipient, Notification::ORDER_READY, $session, null, $table, $fields + ['group_key' => $group]);
    }

    private static function checkRequested(array $event): void
    {
        // Sin actor es el paso a REQUESTED del primer pago: quien cobra ya lo sabe.
        $actor = $event['payload']['actorId'] ?? null;
        $check = Check::query()->find((string) $event['source_id']);
        $session = $check ? TableSession::query()->find((string) $check->session_id) : null;
        if (! $actor || ! $session) {
            return;
        }
        $branchId = (string) $check->branch_id;
        $cashiers = Membership::query()->where('branch_id', new ObjectId($branchId))->where('status', Membership::ACTIVE)->get()
            ->filter(fn (Membership $m) => $m->can('cash.close'))->map(fn ($m) => (string) $m->user_id);
        $recipients = $cashiers->push((string) $session->responsible_user_id)->filter()->unique()->reject(fn ($id) => $id === (string) $actor);
        $table = self::tableNumber($session);
        $total = Floor::money($check->total);
        foreach ($recipients as $userId) {
            self::create($branchId, $userId, Notification::CHECK_REQUESTED, $session, $check, $table, [
                'title' => "Mesa $table · cuenta solicitada",
                'body' => "Cuenta por \${$total['amount']} {$total['currency']}.",
            ]);
        }
    }

    private static function paymentResult(array $event): void
    {
        $payment = Payment::query()->find((string) $event['source_id']);
        $check = $payment ? Check::query()->find((string) $payment->check_id) : null;
        $session = $check ? TableSession::query()->find((string) $check->session_id) : null;
        $creator = $payment ? (string) $payment->created_by : '';
        $actor = $event['payload']['actorId'] ?? null;
        // Quien registró el resultado ya lo vio; el efectivo se confirma al registrarse.
        if (! $session || $creator === '' || ($actor !== null && (string) $actor === $creator) || $payment->method === 'CASH') {
            return;
        }
        $table = self::tableNumber($session);
        $amount = Floor::money($payment->amount);
        $money = "\${$amount['amount']} {$amount['currency']}";
        [$type, $title, $body] = match ($event['type']) {
            'payment.confirmed' => [Notification::PAYMENT_CONFIRMED, "Mesa $table · pago aprobado", "El pago con tarjeta por $money se aprobó."],
            'payment.failed' => [Notification::PAYMENT_FAILED, "Mesa $table · pago rechazado", "El pago con tarjeta por $money no pasó. Cóbralo de otra forma."],
            default => [Notification::PAYMENT_UNKNOWN, "Mesa $table · pago sin confirmar", "No hubo resultado del pago por $money. Un gerente debe conciliarlo."],
        };
        self::create((string) $check->branch_id, $creator, $type, $session, $check, $table, ['title' => $title, 'body' => $body]);
    }

    private static function create(string $branchId, string $userId, string $type, TableSession $session, ?Check $check, int $table, array $fields): void
    {
        Notification::query()->create($fields + [
            'branch_id' => new ObjectId($branchId),
            'user_id' => new ObjectId($userId),
            'type' => $type,
            'session_id' => new ObjectId($session->getKey()),
            'check_id' => $check ? new ObjectId($check->getKey()) : null,
            'table_number' => $table,
            'count' => $fields['count'] ?? 1,
            'read_at' => null,
            'expires_at' => now()->addDays(self::KEEP_DAYS),
        ]);
    }

    /** Número de la primera mesa activa de la sesión (o de la primera que tuvo). */
    private static function tableNumber(TableSession $session): int
    {
        $assignments = TableAssignment::query()->where('session_id', new ObjectId($session->getKey()))->orderBy('assigned_at')->get();
        $first = $assignments->firstWhere('active', true) ?? $assignments->first();

        return $first ? (int) RestaurantTable::query()->find((string) $first->table_id)?->number : 0;
    }

    /** Marca leídos los avisos del usuario en la sucursal; devuelve cuántos cambiaron. */
    public static function readAll(Branch $branch, string $userId): int
    {
        // toBase(): sin tocar updated_at, que es la hora del aviso.
        return Notification::query()->forBranch($branch)->where('user_id', new ObjectId($userId))->whereNull('read_at')
            ->toBase()->update(['read_at' => new UTCDateTime(now())]);
    }
}
