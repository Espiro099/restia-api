<?php

namespace App\Printing;

use App\Floor\Kitchen;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemUnit;
use App\Models\Printer;
use App\Models\Station;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use MongoDB\BSON\ObjectId;

/**
 * Documento neutro (PR-4): líneas que el agente convierte a ESC/POS. Se genera al crear el trabajo y
 * no cambia después, así que una reimpresión muestra lo original aunque cambie el menú. Nunca lleva
 * datos de tarjeta, RFC ni del receptor.
 */
final class Documents
{
    /**
     * Comanda de una estación: lo que esa estación tiene que preparar de una comanda.
     *
     * @param  Collection<int, OrderItemUnit>  $units  unidades de la comanda en esa estación
     */
    public static function kitchenTicket(Branch $branch, Order $order, Station $station, Collection $units): array
    {
        $items = OrderItem::query()->whereIn('_id', $units->map(fn ($u) => new ObjectId((string) $u->order_item_id))->unique()->values()->all())->get()
            ->keyBy(fn ($i) => (string) $i->getKey());
        $lines = [
            self::text('COMANDA', align: 'CENTER', bold: true, large: true),
            self::text($station->name, align: 'CENTER', bold: true),
            ...self::header($branch, $order),
            self::separator(),
        ];
        foreach ($units->groupBy(fn ($u) => (string) $u->order_item_id) as $itemId => $rows) {
            $lines = [...$lines, ...self::item($items[$itemId], $rows->count())];
        }

        return [...$lines, self::separator(), self::cut()];
    }

    /** Aviso de anulación (PR-3): cocina debe dejar de preparar una unidad que ya empezó. */
    public static function voidNotice(Branch $branch, Order $order, Station $station, OrderItemUnit $unit): array
    {
        $item = OrderItem::query()->find((string) $unit->order_item_id);
        $lines = [
            self::text('ANULACIÓN', align: 'CENTER', bold: true, large: true),
            self::text($station->name, align: 'CENTER', bold: true),
            ...self::header($branch, $order),
            self::separator(),
            ...self::item($item, 1),
        ];
        if ($unit->reason) {
            $lines[] = self::text('Motivo: '.$unit->reason);
        }

        return [...$lines, self::separator(), self::cut()];
    }

    public static function test(Branch $branch, Printer $printer): array
    {
        return [
            self::text('PRUEBA DE IMPRESIÓN', align: 'CENTER', bold: true, large: true),
            self::text($branch->name, align: 'CENTER'),
            self::separator(),
            self::row('Impresora', $printer->name),
            self::row('Código', $printer->code),
            self::row('Papel', $printer->paper_width.' mm'),
            self::row('Fecha', self::local($branch, now())->format('Y-m-d H:i')),
            self::separator(),
            self::cut(),
        ];
    }

    /** La marca que lleva una reimpresión (PR-8), antes del contenido original. */
    public static function reprintBanner(): array
    {
        return [self::text('*** REIMPRESIÓN ***', align: 'CENTER', bold: true), self::separator()];
    }

    private static function header(Branch $branch, Order $order): array
    {
        $tables = Kitchen::tableNumbers(new ObjectId($branch->getKey()), collect([$order->session_id]))[(string) $order->session_id] ?? [];

        return [
            self::row($tables ? 'Mesa '.implode(', ', $tables) : 'Sin mesa', self::local($branch, $order->created_at)->format('H:i')),
            self::text('Comanda '.strtoupper(substr($order->uuid, 0, 8))),
        ];
    }

    private static function item(OrderItem $item, int $quantity): array
    {
        $lines = [self::text($quantity.' x '.$item->name_snapshot, bold: true, large: true)];
        foreach ($item->modifiers_snapshot ?? [] as $m) {
            $lines[] = self::text('  + '.$m['name']);
        }
        if ($item->notes) {
            $lines[] = self::text('  Nota: '.$item->notes, bold: true);
        }

        return $lines;
    }

    private static function local(Branch $branch, mixed $at): Carbon
    {
        return Carbon::instance($at)->setTimezone($branch->timezone ?: 'America/Mexico_City');
    }

    private static function text(string $text, string $align = 'LEFT', bool $bold = false, bool $large = false): array
    {
        return ['kind' => 'TEXT', 'text' => $text, 'align' => $align, 'bold' => $bold, 'size' => $large ? 'LARGE' : 'NORMAL'];
    }

    private static function row(string $left, string $right): array
    {
        return ['kind' => 'ROW', 'text' => $left, 'right' => $right];
    }

    private static function separator(): array
    {
        return ['kind' => 'SEPARATOR'];
    }

    private static function cut(): array
    {
        return ['kind' => 'CUT'];
    }
}
