<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public static function next(string $key, string $prefix, ?int $year = null, int $padding = 4): string
    {
        return DB::transaction(function () use ($key, $prefix, $year, $padding): string {
            $sequence = DocumentSequence::where('key', $key)->lockForUpdate()->first();

            if (! $sequence) {
                try {
                    $sequence = DocumentSequence::create([
                        'key' => $key,
                        'prefix' => $prefix,
                        'year' => $year,
                        'next_number' => 1,
                        'padding' => $padding,
                    ]);
                    $sequence = DocumentSequence::whereKey($sequence->id)->lockForUpdate()->first();
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    $sequence = DocumentSequence::where('key', $key)->lockForUpdate()->firstOrFail();
                }
            }

            $number = $sequence->next_number;
            $sequence->increment('next_number');

            return $sequence->prefix.'-'.($year ? $year.'-' : '').str_pad((string) $number, $sequence->padding, '0', STR_PAD_LEFT);
        });
    }

    public static function nextAdmission(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return self::next('admission:'.$year, 'TSS-ADM', $year, 4);
    }

    public static function nextApplication(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return self::next('application:'.$year, 'TSS-APP', $year, 4);
    }

    public static function nextInvoice(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = (string) \App\Models\Setting::get('invoice_prefix', 'TSS-INV');

        return self::next('invoice:'.$year, $prefix, $year, 6);
    }

    public static function nextReceipt(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = (string) \App\Models\Setting::get('receipt_prefix', 'TSS-RCT');

        return self::next('receipt:'.$year, $prefix, $year, 6);
    }

    public static function nextExpense(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return self::next('expense:'.$year, 'TSS-EXP', $year, 5);
    }

    public static function nextIncome(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return self::next('income:'.$year, 'TSS-INC', $year, 5);
    }

    public static function nextRefund(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return self::next('refund:'.$year, 'TSS-REF', $year, 5);
    }
}
