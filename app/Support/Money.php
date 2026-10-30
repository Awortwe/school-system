<?php

namespace App\Support;

final class Money
{
    public const CURRENCY = 'GHS';

    public static function fromMajor(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $value = str_replace([',', ' '], '', (string) $value);

        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $value)) throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'Enter a decimal amount with at most two decimal places.']);
        if (bccomp(ltrim($value, '-'), bcdiv((string) PHP_INT_MAX, '100', 2), 2) > 0) throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'Amount is too large.']);

        $minor = bcmul($value, '100', 2);

        return (int) (bccomp($minor, '0', 2) >= 0
            ? bcadd($minor, '0.5', 0)
            : bcsub($minor, '0.5', 0));
    }

    public static function toMajor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);
        $major = intdiv($abs, 100);
        $cents = $abs % 100;

        return $sign.number_format($major).'.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $minor, bool $withCurrency = true): string
    {
        return ($withCurrency ? self::CURRENCY.' ' : '').self::toMajor($minor);
    }

    public static function add(int ...$amounts): int
    {
        return array_sum($amounts);
    }

    public static function proportional(int $amount, int $part, int $whole): int
    {
        if ($whole <= 0 || $part <= 0 || $amount <= 0) return 0;
        return (int) bcadd(bcdiv(bcmul((string) $amount, (string) $part, 0), (string) $whole, 8), '0.5', 0);
    }

    public static function percentOf(int $minor, string|float $percent): int
    {
        $result = bcmul((string) $minor, bcdiv((string) $percent, '100', 8), 2);

        return (int) (bccomp($result, '0', 2) >= 0
            ? bcadd($result, '0.5', 0)
            : bcsub($result, '0.5', 0));
    }
}
