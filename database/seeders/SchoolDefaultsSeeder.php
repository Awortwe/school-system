<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SchoolDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'school_name' => 'The Sanctuary Schools',
            'school_short_name' => 'TSS',
            'school_tagline' => 'Shaping futures, securing destinies',
            'school_motto' => 'Shaping futures, securing destinies',
            'school_address' => 'Mpatado, Anaji, Takoradi, Ghana',
            'school_phone' => '+233 50 652 5455',
            'school_email' => 'info@thesanctuary.edu.gh',
            'admissions_phone_montessori' => '+233 50 954 2373',
            'admissions_phone_international' => '+233 50 970 7929',
            'school_logo' => '/images/branding/tss-logo.png',
            'primary_color' => '#00069C',
            'navy_color' => '#00069C',
            'gold_color' => '#F1D311',
            'sky_color' => '#0195FF',
            'red_color' => '#FE0000',
            'currency' => 'GHS',
            'currency_symbol' => 'GH₵',
            'timezone' => 'Africa/Accra',
            'headteacher_name' => 'Headteacher',
            'headteacher_title' => 'Headteacher',
            'class_teacher_signature_name' => 'Class Teacher',
            'invoice_due_days' => 14,
            'invoice_prefix' => 'TSS-INV',
            'receipt_prefix' => 'TSS-RCT',
            'report_show_position' => false,
            'report_show_attendance' => true,
            'report_show_conduct' => true,
            'report_show_signatures' => true,
            'result_require_approval' => true,
            'result_ranking' => 'competition',
            'result_decimal_places' => 1,
            'promotion_min_average' => 50,
            'promotion_min_pass_percent' => 50,
            'promotion_max_core_failures' => 2,
            'attendance_sessions' => ['morning'],
            'attendance_statuses' => ['present', 'absent', 'late', 'excused'],
            'attendance_late_threshold' => '08:00',
            'payment_methods' => ['cash', 'momo', 'bank_transfer', 'cheque'],
            'student_categories' => ['day', 'boarding'],
            'boarding_from_grade' => 6,
            'ga_phone' => '+233 50 652 5455',
        ]; foreach ($defaults as $key => $value) { Setting::firstOrCreate(['key' => $key], ['value' => json_encode($value), 'group' => 'school', 'type' => 'json']); } \Illuminate\Support\Facades\Cache::forget('settings.all');
    }
}

