<?php

namespace App\Printing;

use App\Cash\Summary;
use App\Checks\Totals;
use App\Floor\Kitchen;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Check;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemUnit;
use App\Models\Payment;
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

    /**
     * Precuenta: lo que se cobra en la cuenta, agrupado por platillo y precio, con cortesías y saldo.
     * Las unidades canceladas o anuladas no aparecen.
     */
    public static function preCheck(Branch $branch, Check $check): array
    {
        $lines = [
            self::text('PRECUENTA', align: 'CENTER', bold: true, large: true),
            self::text($branch->name, align: 'CENTER'),
            self::row(self::tables($branch, $check), self::local($branch, now())->format('Y-m-d H:i')),
            self::text('Cuenta '.$check->number.($check->label ? ' · '.$check->label : '')),
            self::separator(),
        ];
        $billed = Totals::lines($check)->filter(fn ($l) => $l['billed']);
        foreach ($billed->groupBy(fn ($l) => $l['item']->name_snapshot.'|'.$l['price']) as $rows) {
            $first = $rows->first();
            $lines[] = self::row($rows->count().' x '.$first['item']->name_snapshot, self::money(bcmul($first['price'], (string) $rows->count(), 2)));
            foreach ($first['item']->modifiers_snapshot ?? [] as $m) {
                $lines[] = self::text('  + '.$m['name']);
            }
        }
        foreach ($billed->filter(fn ($l) => $l['adjustment']) as $l) {
            $lines[] = self::row('  Cortesía: '.$l['item']->name_snapshot, '-'.self::money((string) $l['adjustment']->amount));
        }
        $lines = [...$lines, self::separator(),
            self::row('Subtotal', self::money((string) $check->subtotal)),
            self::row('IVA', self::money((string) $check->tax_total)),
        ];
        if (bccomp((string) ($check->adjustment_total ?? '0'), '0', 2) !== 0) {
            $lines[] = self::row('Cortesías', '-'.self::money((string) $check->adjustment_total));
        }
        $lines[] = self::text('TOTAL '.self::money((string) $check->total), align: 'RIGHT', bold: true, large: true);
        if (bccomp((string) ($check->paid_total ?? '0'), '0', 2) !== 0) {
            $lines[] = self::row('Pagado', self::money((string) $check->paid_total));
            $lines[] = self::row('Por pagar', self::money((string) $check->balance));
        }

        return [...$lines, self::separator(), self::text('Propina no incluida', align: 'CENTER'), self::cut()];
    }

    /** Recibo de un pago confirmado. De la tarjeta solo va la marca: nunca número, RFC ni receptor (PR-4). */
    public static function paymentReceipt(Branch $branch, Payment $payment, Check $check): array
    {
        $methods = ['CASH' => 'Efectivo', 'CARD_CREDIT' => 'Tarjeta de crédito', 'CARD_DEBIT' => 'Tarjeta de débito', 'TRANSFER' => 'Transferencia'];
        $method = $methods[$payment->method] ?? $payment->method;
        $card = $payment->card ? (array) $payment->card : [];
        if (! empty($card['brand'])) {
            $method .= ' '.$card['brand'];
        }
        $lines = [
            self::text('RECIBO DE PAGO', align: 'CENTER', bold: true, large: true),
            self::text($branch->name, align: 'CENTER'),
            self::row(self::tables($branch, $check), self::local($branch, $payment->confirmed_at ?? $payment->created_at)->format('Y-m-d H:i')),
            self::text('Cuenta '.$check->number.' · Pago '.strtoupper(substr($payment->uuid, 0, 8))),
            self::separator(),
            self::row('Forma de pago', $method),
            self::row('Importe', self::money((string) $payment->amount)),
        ];
        $tip = (string) ($payment->tip ?? '0');
        if (bccomp($tip, '0', 2) !== 0) {
            $lines[] = self::row('Propina', self::money($tip));
            $lines[] = self::row('Total cobrado', self::money(bcadd((string) $payment->amount, $tip, 2)));
        }
        if ($payment->amount_tendered !== null) {
            $lines[] = self::row('Recibido', self::money((string) $payment->amount_tendered));
            $lines[] = self::row('Cambio', self::money((string) ($payment->change ?? '0')));
        }

        return [...$lines, self::separator(),
            self::text('Este documento no es un comprobante fiscal.', align: 'CENTER'),
            self::text('Gracias por su visita', align: 'CENTER'),
            self::cut(),
        ];
    }

    /** Reporte de un turno: con el turno activo es parcial; cerrado, sale del resumen congelado. */
    public static function cashShiftReport(Branch $branch, CashShift $shift, CashRegister $register): array
    {
        $summary = Summary::of($shift);
        $amount = fn (array $money) => self::money($money['amount']);
        $closed = $shift->status === CashShift::CLOSED;
        $lines = [
            self::text('REPORTE DE CAJA', align: 'CENTER', bold: true, large: true),
            self::text($branch->name, align: 'CENTER'),
            self::text($register->name, align: 'CENTER', bold: true),
        ];
        if (! $closed) {
            $lines[] = self::text('*** PARCIAL: TURNO ABIERTO ***', align: 'CENTER', bold: true);
        }
        $lines = [...$lines,
            self::row('Apertura', self::local($branch, $shift->opened_at)->format('Y-m-d H:i')),
            self::row($closed ? 'Cierre' : 'Corte', self::local($branch, $closed ? $shift->closed_at : now())->format('Y-m-d H:i')),
            self::separator(),
            self::row('Fondo inicial', $amount($summary['openingAmount'])),
            self::row('Depósitos', $amount($summary['deposits'])),
            self::row('Retiros', '-'.$amount($summary['withdrawals'])),
            self::separator(),
        ];
        $names = ['CASH' => 'Efectivo', 'CARD_CREDIT' => 'Crédito', 'CARD_DEBIT' => 'Débito', 'TRANSFER' => 'Transferencia'];
        foreach ($summary['byMethod'] as $m) {
            $lines[] = self::text($names[$m['method']] ?? $m['method'], bold: true);
            $lines[] = self::row('  Ventas', $amount($m['sales']));
            $lines[] = self::row('  Propinas', $amount($m['tips']));
            if (bccomp($m['refunds']['amount'], '0', 2) !== 0 || bccomp($m['tipsRefunded']['amount'], '0', 2) !== 0) {
                $lines[] = self::row('  Reembolsos', '-'.self::money(bcadd($m['refunds']['amount'], $m['tipsRefunded']['amount'], 2)));
            }
        }
        $lines = [...$lines, self::separator(), self::row('Efectivo esperado', $amount($summary['expectedCash']))];
        if ($closed) {
            $lines[] = self::row('Efectivo contado', $amount($summary['countedCash']));
            $lines[] = self::row('Diferencia', $amount($summary['difference']));
        } else {
            $pending = array_sum($summary['unresolved']);
            if ($pending > 0) {
                $lines[] = self::text("Operaciones sin resolver: $pending", bold: true);
            }
        }

        return [...$lines, self::separator(), self::cut()];
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

    /** Mesas de la sesión de una cuenta, o "Sin mesa". */
    private static function tables(Branch $branch, Check $check): string
    {
        $tables = Kitchen::tableNumbers(new ObjectId($branch->getKey()), collect([$check->session_id]))[(string) $check->session_id] ?? [];

        return $tables ? 'Mesa '.implode(', ', $tables) : 'Sin mesa';
    }

    /** Importe con signo de pesos y separador de miles, sin pasar por float. */
    private static function money(string $amount): string
    {
        $amount = bcadd($amount, '0', 2);
        $negative = str_starts_with($amount, '-');
        [$int, $dec] = explode('.', ltrim($amount, '-'));
        $int = strrev(implode(',', str_split(strrev($int), 3)));

        return ($negative ? '-' : '').'$'.$int.'.'.$dec;
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
