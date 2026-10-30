<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    protected $guarded = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::allValues();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, mixed $value, string $group = 'general', string $type = 'string'): void
    {
        self::updateOrCreate(
            ['key' => $key],
            [
                'value' => json_encode($value),
                'group' => $group,
                'type' => $type,
            ],
        );

        Cache::forget('settings.all');
    }

    public static function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            self::updateOrCreate(
                ['key' => $key],
                ['value' => json_encode($value), 'group' => $group, 'type' => 'json'],
            );
        }

        Cache::forget('settings.all');
    }

    public static function allValues(): array
    {
        try {
            return Cache::rememberForever('settings.all', function (): array {
                return self::query()->pluck('value', 'key')->map(function ($value) {
                    return json_decode((string) $value, true);
                })->all();
            });
        } catch (\Throwable) {
            return self::defaults();
        }
    }

    public static function school(): array
    {
        $values = self::allValues();
        $defaults = self::defaults();

        return [
            'name' => $values['school_name'] ?? $defaults['school_name'],
            'short_name' => $values['school_short_name'] ?? $defaults['school_short_name'],
            'motto' => $values['school_motto'] ?? $defaults['school_motto'],
            'address' => $values['school_address'] ?? $defaults['school_address'],
            'phone' => $values['school_phone'] ?? $defaults['school_phone'],
            'email' => $values['school_email'] ?? $defaults['school_email'],
            'logo_url' => $values['school_logo'] ?? $defaults['school_logo'],
            'primary_color' => $values['primary_color'] ?? $defaults['primary_color'],
            'navy_color' => $values['navy_color'] ?? $defaults['navy_color'],
            'gold_color' => $values['gold_color'] ?? $defaults['gold_color'],
            'sky_color' => $values['sky_color'] ?? $defaults['sky_color'],
            'current_academic_year' => $values['current_academic_year'] ?? null,
            'current_term' => $values['current_term'] ?? null,
        ];
    }

    public static function defaults(): array
    {
        return [
            'school_name' => 'The Sanctuary Schools',
            'school_short_name' => 'TSS',
            'school_motto' => 'Shaping futures, securing destinies',
            'school_address' => 'Mpatado, Anaji, Takoradi, Ghana',
            'school_phone' => '+233 50 652 5455',
            'school_email' => 'info@thesanctuary.edu.gh',
            'school_logo' => '/images/branding/tss-logo.png',
            'primary_color' => '#00069C',
            'navy_color' => '#00069C',
            'gold_color' => '#F1D311',
            'sky_color' => '#0195FF',
            'currency' => 'GHS',
            'timezone' => 'Africa/Accra',
            'attendance_sessions' => ['morning'],
            'late_threshold' => '08:00',
            'report_show_position' => false,
            'report_show_attendance' => true,
            'report_show_conduct' => true,
            'report_show_signatures' => true,
            'invoice_prefix' => 'TSS-INV',
            'receipt_prefix' => 'TSS-RCT',
        ];
    }
}
