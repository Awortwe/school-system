<?php

namespace App\Http\Controllers;

use App\Models\ReportSignature;
use App\Models\Setting;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    private const GROUPS = [
        'school_name' => 'school',
        'school_short_name' => 'school',
        'school_motto' => 'school',
        'school_address' => 'school',
        'school_phone' => 'school',
        'school_email' => 'school',
        'admissions_phone_montessori' => 'school',
        'admissions_phone_international' => 'school',
        'primary_color' => 'school',
        'navy_color' => 'school',
        'gold_color' => 'school',
        'sky_color' => 'school',
        'currency' => 'finance',
        'timezone' => 'general',
        'invoice_prefix' => 'finance',
        'receipt_prefix' => 'finance',
        'payment_instructions' => 'finance',
        'thermal_width' => 'finance',
        'invoice_due_days' => 'finance',
        'payment_methods' => 'finance',
        'late_threshold' => 'attendance',
        'attendance_sessions' => 'attendance',
        'report_show_position' => 'reports',
        'report_show_attendance' => 'reports',
        'report_show_conduct' => 'reports',
        'report_show_signatures' => 'reports',
        'promotion_average' => 'academics',
        'ranking_method' => 'academics',
        'score_decimal_places' => 'academics',
        'ges_continuous_weight' => 'academics',
        'ges_exam_weight' => 'academics',
        'cambridge_continuous_weight' => 'academics',
        'cambridge_exam_weight' => 'academics',
    ];

    public function edit(): Response
    {
        $values = array_merge(Setting::defaults(), Setting::allValues());

        return Inertia::render('Settings/Edit', [
            'settings' => $values,
            'signatures' => ReportSignature::orderBy('sort_order')->get(),
            'templates' => \App\Models\ReportCardTemplate::orderByDesc('id')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
            'settings.school_name' => ['sometimes', 'required', 'string', 'max:200'],
            'settings.school_email' => ['nullable', 'email', 'max:200'],
            'settings.school_address' => ['nullable', 'string', 'max:500'],
            'settings.school_phone' => ['nullable', 'string', 'max:50'],
            'settings.school_motto' => ['nullable', 'string', 'max:250'],
            'settings.school_short_name' => ['nullable', 'string', 'max:30'],
            'settings.primary_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.navy_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.gold_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.sky_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.currency' => ['sometimes', 'in:GHS'],
            'settings.timezone' => ['sometimes', 'in:Africa/Accra'],
            'settings.invoice_prefix' => ['sometimes', 'alpha_dash', 'max:30'],
            'settings.receipt_prefix' => ['sometimes', 'alpha_dash', 'max:30'],
            'settings.invoice_due_days' => ['sometimes', 'integer', 'between:0,365'],
            'settings.payment_methods' => ['sometimes', 'array', 'min:1'],
            'settings.payment_methods.*' => ['required', 'alpha_dash', 'max:30'],
            'settings.attendance_sessions' => ['sometimes', 'array', 'min:1'],
            'settings.attendance_sessions.*' => ['required', 'alpha_dash', 'max:30'],
            'settings.payment_instructions' => ['nullable', 'string', 'max:2000'],
            'settings.thermal_width' => ['sometimes', 'in:58,80'],
            'settings.score_decimal_places' => ['sometimes', 'integer', 'between:0,2'],
            'settings.ranking_method' => ['sometimes', 'in:competition,dense'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $changes = [];

        foreach ($data['settings'] as $key => $value) {
            if (! array_key_exists($key, self::GROUPS)) {
                continue;
            }

            if (in_array($key, ['report_show_position', 'report_show_attendance', 'report_show_conduct', 'report_show_signatures'], true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }

            if (in_array($key, ['invoice_due_days', 'promotion_average', 'score_decimal_places', 'ges_continuous_weight', 'ges_exam_weight', 'cambridge_continuous_weight', 'cambridge_exam_weight'], true)) {
                $value = (int) $value;
            }

            if (in_array($key, ['payment_methods', 'attendance_sessions'], true)) {
                $value = array_values(array_filter((array) $value));
            }

            Setting::set($key, $value, self::GROUPS[$key], is_array($value) || is_bool($value) ? 'json' : 'string');
            $changes[$key] = $value;
        }

        if ($request->hasFile('logo')) {
            $old = Setting::get('school_logo');
            $path = $request->file('logo')->store('branding', 'public');
            Setting::set('school_logo', Storage::disk('public')->url($path), 'school');

            if ($old && str_starts_with((string) $old, '/storage/')) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $old));
            }

            $changes['school_logo'] = $path;
        }

        Audit::record('settings_updated', null, [], $changes, null, 'School settings updated');

        return back()->with('success', 'Settings updated.');
    }

    public function storeSignature(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:150'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:1024'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $path = $request->hasFile('image') ? $request->file('image')->store('signatures', 'local') : null;

        ReportSignature::create([
            'title' => $data['title'],
            'name' => $data['name'],
            'image_path' => $path,
            'is_active' => true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return back()->with('success', 'Report signature added.');
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'title' => ['required', 'string', 'max:100'], 'footer' => ['nullable', 'string', 'max:500'], 'show_position' => ['boolean'], 'show_attendance' => ['boolean'], 'show_conduct' => ['boolean'], 'show_signatures' => ['boolean']]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            \App\Models\ReportCardTemplate::query()->update(['is_default' => false]);
            $template = \App\Models\ReportCardTemplate::create(['name' => $data['name'], 'is_default' => true, 'config' => \Illuminate\Support\Arr::except($data, ['name'])]);
            Audit::record('report_template_created', $template, [], ['name' => $data['name']], null, 'New default report template version');
        });
        return back()->with('success', 'New report template saved. Published reports retain their prior template.');
    }

    public function destroySignature(ReportSignature $signature): RedirectResponse
    {
        if ($signature->image_path) {
            Storage::disk('public')->delete($signature->image_path);
        }

        $signature->delete();

        return back()->with('success', 'Report signature removed.');
    }
}
