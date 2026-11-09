<?php

namespace App\Checks;

use App\Models\Check;
use App\Models\CheckAdjustment;
use App\Models\CheckAllocation;
use App\Models\OrderItem;
use App\Models\OrderItemUnit;
use App\Support\Floor;
use Illuminate\Support\Collection;
use MongoDB\BSON\ObjectId;

/**
 * Totales de una cuenta (C-6), con los datos copiados en la partida. Los precios incluyen impuestos
 * (M-7): la base se obtiene dividiendo entre 1 + tasa, por unidad, con redondeo a 2 decimales.
 * `total = subtotal + taxTotal − cortesías` y `balance = total − paidTotal`.
 */
final class Totals
{
    /** Las canceladas y anuladas no se cobran; una PENDING sí. */
    public const UNBILLED = [OrderItemUnit::CANCELLED, OrderItemUnit::VOIDED];

    /**
     * Líneas de la cuenta: cada unidad asignada con su precio, impuesto y cortesía.
     *
     * @return Collection<string, array{unit: OrderItemUnit, item: OrderItem, price: string, tax: string, adjustment: ?CheckAdjustment, billed: bool}> por id de unidad
     */
    public static function lines(Check $check): Collection
    {
        $unitIds = CheckAllocation::query()->where('check_id', new ObjectId($check->getKey()))->where('active', true)->get(['order_item_unit_id'])
            ->map(fn ($a) => new ObjectId((string) $a->order_item_unit_id))->all();

        return self::linesFor($unitIds);
    }

    /** @param list<ObjectId> $unitIds */
    public static function linesFor(array $unitIds): Collection
    {
        if (! $unitIds) {
            return collect();
        }
        $units = OrderItemUnit::query()->whereIn('_id', $unitIds)->orderBy('order_id')->orderBy('order_item_id')->orderBy('unit_number')->get();
        $items = OrderItem::query()->whereIn('_id', $units->map(fn ($u) => new ObjectId((string) $u->order_item_id))->unique()->values()->all())->get()
            ->keyBy(fn ($i) => (string) $i->getKey());
        $adjustments = CheckAdjustment::query()->whereIn('order_item_unit_id', $unitIds)->where('active', true)->get()
            ->keyBy(fn ($a) => (string) $a->order_item_unit_id);

        return $units->mapWithKeys(function (OrderItemUnit $u) use ($items, $adjustments) {
            $item = $items[(string) $u->order_item_id];
            $price = self::unitPrice($item);

            return [(string) $u->getKey() => [
                'unit' => $u,
                'item' => $item,
                'price' => $price,
                'tax' => self::tax($price, (string) (((array) $item->fiscal_snapshot)['tax_rate'] ?? '0')),
                'adjustment' => $adjustments->get((string) $u->getKey()),
                'billed' => ! in_array($u->status, self::UNBILLED, true),
            ]];
        });
    }

    /** Precio de una unidad: el del producto más los de sus opciones, todo copiado al comandar. */
    public static function unitPrice(OrderItem $item): string
    {
        $price = bcadd((string) $item->unit_price, '0', 2);
        foreach ($item->modifiers_snapshot ?? [] as $m) {
            $price = bcadd($price, (string) $m['price_delta'], 2);
        }

        return $price;
    }

    /** Impuesto incluido en un precio: precio − redondeo(precio / (1 + tasa)). */
    public static function tax(string $price, string $rate): string
    {
        $base = self::round(bcdiv($price, bcadd('1', $rate, 6), 8));

        return bcsub($price, $base, 2);
    }

    /**
     * Campos de totales para guardar en la cuenta, y cuántas unidades se cobran.
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    public static function compute(Check $check): array
    {
        $subtotal = $tax = $adjusted = '0';
        $billed = 0;
        foreach (self::lines($check) as $line) {
            if (! $line['billed']) {
                continue;
            }
            $billed++;
            $tax = bcadd($tax, $line['tax'], 2);
            $subtotal = bcadd($subtotal, bcsub($line['price'], $line['tax'], 2), 2);
            if ($line['adjustment']) {
                $adjusted = bcadd($adjusted, (string) $line['adjustment']->amount, 2);
            }
        }
        $total = bcsub(bcadd($subtotal, $tax, 2), $adjusted, 2);
        $paid = bcadd((string) ($check->paid_total ?? '0'), '0', 2);

        return [[
            'subtotal' => Floor::decimal($subtotal),
            'tax_total' => Floor::decimal($tax),
            'adjustment_total' => Floor::decimal($adjusted),
            'total' => Floor::decimal($total),
            'balance' => Floor::decimal(bcsub($total, $paid, 2)),
        ], $billed];
    }

    /** Redondeo a 2 decimales, mitad hacia arriba. */
    private static function round(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.005' : '0.005', 2);
    }
}
