<?php

namespace App\Cash;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Support\Floor;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

/**
 * Resumen y efectivo esperado de un turno (CA-6):
 *
 *   esperado = fondo inicial + efectivo cobrado (amount + tip de pagos CASH confirmados) + depósitos
 *            − retiros − efectivo devuelto (amount + tip de reembolsos CASH confirmados)
 *
 * Con el turno activo se calcula en cada lectura; al cerrar se guarda y deja de cambiar. Pagos y
 * reembolsos llegan con Payments: esta clase ya lee sus colecciones.
 */
final class Summary
{
    public const METHODS = ['CASH', 'CARD_CREDIT', 'CARD_DEBIT', 'TRANSFER'];

    /** @return array<string, mixed> */
    public static function of(CashShift $shift): array
    {
        if ($shift->status === CashShift::CLOSED && $shift->closing_summary) {
            return ['frozen' => true] + (array) json_decode(json_encode($shift->closing_summary), true);
        }
        $shiftId = new ObjectId($shift->getKey());
        $movements = CashMovement::query()->where('cash_shift_id', $shiftId)->get(['type', 'amount']);
        $sum = fn ($rows) => $rows->reduce(fn ($carry, $m) => bcadd($carry, (string) $m->amount, 2), '0');
        $deposits = $sum($movements->where('type', CashMovement::DEPOSIT));
        $withdrawals = $sum($movements->where('type', CashMovement::WITHDRAWAL));

        $payments = self::rows('payments', $shiftId);
        $refunds = self::rows('refunds', $shiftId);
        $byMethod = [];
        foreach (self::METHODS as $method) {
            $pick = fn (array $rows, string $field) => array_reduce(
                array_filter($rows, fn ($r) => $r['status'] === 'CONFIRMED' && $r['method'] === $method),
                fn ($carry, $r) => bcadd($carry, (string) ($r[$field] ?? '0'), 2), '0');
            $byMethod[$method] = [
                'sales' => $pick($payments, 'amount'), 'tips' => $pick($payments, 'tip'),
                'refunds' => $pick($refunds, 'amount'), 'tipsRefunded' => $pick($refunds, 'tip'),
            ];
        }
        $cash = $byMethod['CASH'];
        $expected = bcadd((string) $shift->opening_amount, '0', 2);
        foreach ([$cash['sales'], $cash['tips'], $deposits] as $plus) {
            $expected = bcadd($expected, $plus, 2);
        }
        foreach ([$withdrawals, $cash['refunds'], $cash['tipsRefunded']] as $minus) {
            $expected = bcsub($expected, $minus, 2);
        }
        $count = fn (array $rows, string $status) => count(array_filter($rows, fn ($r) => $r['status'] === $status));

        return [
            'cashShiftId' => $shift->uuid,
            'status' => $shift->status,
            'openingAmount' => Floor::money($shift->opening_amount),
            'deposits' => Floor::money($deposits),
            'withdrawals' => Floor::money($withdrawals),
            'expectedCash' => Floor::money($expected),
            'countedCash' => null,
            'difference' => null,
            'byMethod' => array_map(fn ($m, $t) => [
                'method' => $m, 'sales' => Floor::money($t['sales']), 'tips' => Floor::money($t['tips']),
                'refunds' => Floor::money($t['refunds']), 'tipsRefunded' => Floor::money($t['tipsRefunded']),
            ], array_keys($byMethod), $byMethod),
            'unresolved' => [
                'pendingPayments' => $count($payments, 'PENDING'), 'unknownPayments' => $count($payments, 'UNKNOWN'),
                'pendingRefunds' => $count($refunds, 'PENDING'), 'unknownRefunds' => $count($refunds, 'UNKNOWN'),
            ],
            'frozen' => false,
        ];
    }

    /** @return list<array{status: string, method: string, amount: mixed, tip: mixed}> */
    private static function rows(string $collection, ObjectId $shiftId): array
    {
        return DB::connection('mongodb')->table($collection)->where('cash_shift_id', $shiftId)->get(['status', 'method', 'amount', 'tip'])
            ->map(fn ($r) => (array) $r)->all();
    }
}
