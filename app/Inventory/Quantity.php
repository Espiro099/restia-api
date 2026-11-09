<?php

namespace App\Inventory;

use MongoDB\BSON\Decimal128;

/**
 * Cantidades en la unidad base del ingrediente, con hasta 4 decimales. Se guardan como Decimal128 para
 * que `$inc` sume sin errores de redondeo, y salen como texto sin ceros de más.
 */
final class Quantity
{
    public const PATTERN = '/^\d{1,9}(\.\d{1,4})?$/';

    public const SCALE = 4;

    public static function decimal(string $value): Decimal128
    {
        return new Decimal128(bcadd($value, '0', self::SCALE));
    }

    public static function out(mixed $value): string
    {
        $text = bcadd((string) ($value ?? '0'), '0', self::SCALE);
        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function less(string $a, string $b): bool
    {
        return bccomp($a, $b, self::SCALE) < 0;
    }
}
