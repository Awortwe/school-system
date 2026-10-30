<?php
namespace App\Http\Controllers;

use App\Models\{Setting, User};
use App\Services\PayrollService;
use App\Support\{Audit, Money};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Crypt};
use Inertia\Inertia;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $period = DB::table('payroll_periods')->find($request->integer('period_id')) ?? DB::table('payroll_periods')->latest('id')->first();
        $entries = $period ? DB::table('payroll_entries')->join('users', 'users.id', '=', 'payroll_entries.user_id')->where('payroll_period_id', $period->id)->select('payroll_entries.*', 'users.name', 'users.employee_no')->get()->map(function ($entry) {
            $entry->paid_minor = (int) DB::table('salary_payments')->where('payroll_entry_id', $entry->id)->where('kind', 'salary')->sum('amount_minor');
            $entry->adjustment_minor = (int) DB::table('payroll_adjustments')->where('payroll_entry_id', $entry->id)->sum('net_delta_minor');
            $entry->outstanding_minor = $entry->net_minor + $entry->adjustment_minor - $entry->paid_minor;
            return $entry;
        }) : collect();
        return Inertia::render('Payroll/Index', ['staff' => User::where('is_active', true)->get(['id', 'name']), 'periods' => DB::table('payroll_periods')->latest('id')->get(), 'period' => $period, 'entries' => $entries,
            'profiles' => DB::table('payroll_profiles')->join('users', 'users.id', '=', 'payroll_profiles.user_id')->orderByDesc('effective_from')->get(['payroll_profiles.id', 'users.name', 'effective_from', 'basic_minor', 'frequency', 'tax_status', 'pension_covered', 'payroll_profiles.is_active']),
            'components' => DB::table('salary_components')->join('users', 'users.id', '=', 'salary_components.user_id')->get(['salary_components.*', 'users.name as staff_name']),
            'rules' => DB::table('payroll_rules')->latest('effective_from')->get(['id', 'name', 'effective_from', 'effective_to', 'source']),
            'payments' => DB::table('salary_payments')->when($period, fn ($query) => $query->whereIn('payroll_entry_id', DB::table('payroll_entries')->where('payroll_period_id', $period->id)->select('id')))->latest('id')->paginate(20),
            'summary' => ['gross_minor' => $entries->sum('gross_minor'), 'net_minor' => $entries->sum('net_minor'), 'employee_deductions_minor' => $entries->sum('deductions_minor'), 'employer_cost_minor' => $entries->sum('employer_minor'), 'paid_minor' => $entries->sum('paid_minor'), 'outstanding_minor' => $entries->sum('outstanding_minor')],
            'adjustments' => DB::table('payroll_adjustments')->latest('id')->limit(30)->get()]);
    }
    public function profile(Request $request)
    {
        $data = $request->validate(['user_id' => ['required', 'exists:users,id'], 'effective_from' => ['required', 'date'], 'basic' => ['required', 'numeric', 'gt:0'], 'tax_status' => ['required', 'in:resident,nonresident'], 'pension_covered' => ['boolean'], 'tax_relief' => ['nullable', 'numeric', 'min:0'], 'payment_details' => ['nullable', 'string', 'max:2000'], 'is_active' => ['boolean']]);
        abort_if(DB::table('payroll_profiles')->where('user_id', $data['user_id'])->where('effective_from', $data['effective_from'])->exists(), 422, 'A profile already exists for this effective date. Choose a new effective date to preserve history.');
        DB::table('payroll_profiles')->insert(['user_id' => $data['user_id'], 'effective_from' => $data['effective_from'], 'basic_minor' => Money::fromMajor($data['basic']), 'frequency' => 'monthly', 'tax_status' => $data['tax_status'], 'pension_covered' => $data['pension_covered'] ?? false, 'tax_relief_minor' => Money::fromMajor($data['tax_relief'] ?? '0'), 'payment_details' => empty($data['payment_details']) ? null : Crypt::encryptString($data['payment_details']), 'is_active' => $data['is_active'] ?? true, 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('salary_profile_versioned', User::find($data['user_id']), [], ['effective_from' => $data['effective_from']], null, 'Effective-dated salary profile created');
        return back()->with('success', 'Salary profile version saved. Payment details are encrypted.');
    }
    public function bank(Request $request, int $profile)
    {
        $record = DB::table('payroll_profiles')->find($profile); abort_unless($record, 404);
        Audit::record('salary_payment_details_viewed', null, [], ['profile_id' => $profile], null, 'Restricted salary payment details viewed');
        return response()->json(['payment_details' => $record->payment_details ? Crypt::decryptString($record->payment_details) : null])->header('Cache-Control', 'no-store, private');
    }
    public function component(Request $request)
    {
        $data = $request->validate(['user_id' => ['required', 'exists:users,id'], 'payroll_period_id' => ['nullable', 'exists:payroll_periods,id'], 'name' => ['required', 'string', 'max:100'], 'type' => ['required', 'in:earning,deduction,employer'], 'amount' => ['required', 'numeric', 'gt:0'], 'taxable' => ['boolean'], 'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from']]);
        $amount = Money::fromMajor($data['amount']); unset($data['amount']);
        DB::table('salary_components')->insert($data + ['amount_minor' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('salary_component_created', null, [], ['user_id' => $data['user_id'], 'type' => $data['type']], null, 'Allowance, deduction or employer cost configured');
        return back()->with('success', 'Salary component saved. Deductions are after tax; statutory relief is configured separately.');
    }
    public function rule(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'source' => ['required', 'string', 'max:2000'], 'employee_pension_rate' => ['required', 'numeric', 'between:0,100'], 'employer_pension_rate' => ['required', 'numeric', 'between:0,100'], 'nonresident_rate' => ['required', 'numeric', 'between:0,100'], 'pension_min' => ['required', 'numeric', 'min:0'], 'pension_max' => ['required', 'numeric', 'gte:pension_min'], 'bands' => ['required', 'array', 'min:1'], 'bands.*.width' => ['nullable', 'numeric', 'gt:0'], 'bands.*.rate' => ['required', 'numeric', 'between:0,100']]);
        $bands = [];
        foreach ($data['bands'] as $index => $band) {
            abort_if(empty($band['width']) && $index !== count($data['bands']) - 1, 422, 'Only the final bracket may have unlimited width.');
            $bands[] = ['width_minor' => empty($band['width']) ? null : Money::fromMajor($band['width']), 'rate' => $band['rate']];
        }
        abort_unless(end($bands)['width_minor'] === null, 422, 'The final bracket must have unlimited width.');
        $data['bands'] = json_encode($bands); $data['pension_min_minor'] = Money::fromMajor($data['pension_min']); $data['pension_max_minor'] = Money::fromMajor($data['pension_max']); unset($data['pension_min'], $data['pension_max']);
        DB::table('payroll_rules')->insert($data + ['confirmed_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('payroll_rule_created', null, [], ['name' => $data['name'], 'effective_from' => $data['effective_from']], null, 'Effective statutory payroll profile configured');
        return back()->with('success', 'Statutory rule version saved. Review classifications and reliefs before approving payroll.');
    }
    public function period(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'], 'payroll_rule_id' => ['required', 'exists:payroll_rules,id']]);
        $start = \Carbon\Carbon::parse($data['start_date']);
        abort_unless($start->day === 1 && $data['end_date'] === $start->copy()->endOfMonth()->toDateString(), 422, 'Monthly payroll must cover one full calendar month.');
        abort_if(DB::table('payroll_periods')->where('start_date', '<=', $data['end_date'])->where('end_date', '>=', $data['start_date'])->exists(), 422, 'Payroll periods must not overlap.');
        DB::table('payroll_periods')->insert($data + ['status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', 'Draft monthly payroll period created.');
    }
    public function generate(Request $request, int $period, PayrollService $service) { $count = $service->generate($period, $request->user()); return back()->with('success', $count.' payroll entries calculated.'); }
    public function transition(Request $request, int $period)
    {
        $data = $request->validate(['status' => ['required', 'in:review,approved']]);
        abort_unless($request->user()->can($data['status'] === 'approved' ? 'payroll.approve' : 'payroll.manage'), 403);
        DB::transaction(function () use ($request, $period, $data) {
            $row = DB::table('payroll_periods')->where('id', $period)->lockForUpdate()->first();
            abort_unless($row && $row->status === ($data['status'] === 'approved' ? 'review' : 'draft'), 422, 'Invalid payroll stage transition.');
            abort_unless(DB::table('payroll_entries')->where('payroll_period_id', $period)->exists(), 422, 'Calculate payroll before submitting it.');
            DB::table('payroll_periods')->where('id', $period)->update(['status' => $data['status'], 'approved_by' => $data['status'] === 'approved' ? $request->user()->id : null, 'updated_at' => now()]);
            Audit::record('payroll_stage_changed', null, ['status' => $row->status], ['period_id' => $period, 'status' => $data['status']], null, 'Payroll reviewed or approved and locked');
        });
        return back()->with('success', 'Payroll stage updated.');
    }
    public function pay(Request $request, int $entry, PayrollService $service)
    {
        $data = $request->validate(['kind' => ['required', 'in:salary,employer,statutory'], 'amount' => ['required', 'numeric', 'gt:0'], 'payment_date' => ['required', 'date'], 'method' => ['required', \Illuminate\Validation\Rule::in(Setting::get('payment_methods', ['cash', 'momo', 'bank_transfer']))], 'reference' => ['required', 'string', 'max:150'], 'idempotency_key' => ['required', 'uuid']]);
        $service->recordPayment($entry, $data, $request->user());
        return back()->with('success', 'Actual payroll payment recorded and posted to operational expenses once.');
    }
    public function adjust(Request $request, int $entry)
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'not_in:0'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $entry, $data) {
            $row = DB::table('payroll_entries')->where('id', $entry)->lockForUpdate()->first();
            abort_unless($row && DB::table('payroll_periods')->where('id', $row->payroll_period_id)->where('status', 'approved')->exists(), 422, 'Adjustments apply to approved payroll.');
            $delta = Money::fromMajor($data['amount']);
            $net = $row->net_minor + (int) DB::table('payroll_adjustments')->where('payroll_entry_id', $entry)->sum('net_delta_minor') + $delta;
            $paid = DB::table('salary_payments')->where('payroll_entry_id', $entry)->where('kind', 'salary')->sum('amount_minor');
            abort_unless($net >= $paid && $net >= 0, 422, 'Adjustment cannot reduce the salary obligation below payments already recorded.');
            DB::table('payroll_adjustments')->insert(['payroll_entry_id' => $entry, 'net_delta_minor' => $delta, 'reason' => $data['reason'], 'approved_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('payroll_adjustment_approved', null, [], ['entry_id' => $entry, 'net_delta_minor' => $delta], $data['reason'], 'Approved payroll correction recorded without editing the original');
        });
        return back()->with('success', 'Authorised payroll correction recorded.');
    }
    public function payslips(Request $request, int $period)
    {
        $row = DB::table('payroll_periods')->find($period); abort_unless($row && $row->status === 'approved', 422, 'Approve payroll before issuing payslips.');
        $entries = DB::table('payroll_entries')->where('payroll_period_id', $period)->when($request->filled('entry_id'), fn ($query) => $query->where('id', $request->integer('entry_id')))->get()->map(function ($entry) {
            $entry->details = json_decode($entry->snapshot, true);
            $entry->paid_minor = (int) DB::table('salary_payments')->where('payroll_entry_id', $entry->id)->where('kind', 'salary')->sum('amount_minor');
            $entry->adjustments = DB::table('payroll_adjustments')->where('payroll_entry_id', $entry->id)->get();
            return $entry;
        });
        abort_if($entries->isEmpty(), 404);
        Audit::record('payslips_printed', null, [], ['period_id' => $period, 'count' => $entries->count()], null, 'Restricted payslip PDF generated');
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.payslips', ['school' => Setting::school(), 'period' => $row, 'entries' => $entries])->setPaper('a4')->stream('payslips-'.$period.'.pdf');
    }
}

