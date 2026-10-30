<?php
namespace App\Services;

use App\Models\User;
use App\Support\{Money, Audit};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function calculate(array $profile, array $components, array $rule): array
    {
        $basic = (int) $profile['basic_minor']; $gross = $basic; $taxable = $basic; $otherDeductions = 0; $employerExtra = 0;
        foreach ($components as $component) {
            $amount = (int) $component['amount_minor'];
            if ($component['type'] === 'earning') { $gross += $amount; if ($component['taxable']) $taxable += $amount; }
            elseif ($component['type'] === 'deduction') $otherDeductions += $amount;
            else $employerExtra += $amount;
        }
        $insurable = $profile['pension_covered'] ? max((int) $rule['pension_min_minor'], min($basic, (int) $rule['pension_max_minor'])) : 0;
        $employeePension = Money::percentOf($insurable, (string) $rule['employee_pension_rate']);
        $employerPension = Money::percentOf($insurable, (string) $rule['employer_pension_rate']);
        $chargeable = max(0, $taxable - $employeePension - (int) ($profile['tax_relief_minor'] ?? 0));
        $paye = 0;
        if ($profile['tax_status'] === 'nonresident') $paye = Money::percentOf($chargeable, (string) $rule['nonresident_rate']);
        elseif ($profile['tax_status'] === 'resident') {
            $remaining = $chargeable;
            $bands = is_string($rule['bands']) ? json_decode($rule['bands'], true, flags: JSON_THROW_ON_ERROR) : $rule['bands'];
            foreach ($bands as $band) {
                $portion = $band['width_minor'] === null ? $remaining : min($remaining, (int) $band['width_minor']);
                $paye += Money::percentOf($portion, (string) $band['rate']); $remaining -= $portion;
                if ($remaining <= 0) break;
            }
        } else throw ValidationException::withMessages(['classification' => 'Choose a supported resident/nonresident employment classification.']);
        $deductions = $employeePension + $paye + $otherDeductions; $net = $gross - $deductions;
        if ($net < 0) throw ValidationException::withMessages(['salary' => 'Deductions exceed gross pay.']);
        return ['gross_minor' => $gross, 'deductions_minor' => $deductions, 'net_minor' => $net, 'employer_minor' => $employerPension + $employerExtra,
            'snapshot' => ['basic_minor' => $basic, 'components' => $components, 'employee_pension_minor' => $employeePension, 'employer_pension_minor' => $employerPension, 'paye_minor' => $paye, 'chargeable_minor' => $chargeable, 'classification' => $profile['tax_status'], 'rule' => $rule]];
    }
    public function generate(int $periodId, User $actor): int
    {
        return DB::transaction(function () use ($periodId, $actor) {
            $period = DB::table('payroll_periods')->where('id', $periodId)->lockForUpdate()->first();
            abort_unless($period && $period->status === 'draft', 422, 'Only draft payroll can be calculated.');
            $rule = (array) DB::table('payroll_rules')->find($period->payroll_rule_id);
            abort_unless($period->start_date >= $rule['effective_from'] && (! $rule['effective_to'] || $period->end_date <= $rule['effective_to']), 422, 'The statutory rule must cover the full payroll period.');
            $count = 0; $included = [];
            foreach (User::where('is_active', true)->get() as $staff) {
                $profile = DB::table('payroll_profiles')->where('user_id', $staff->id)->where('effective_from', '<=', $period->end_date)->orderByDesc('effective_from')->first();
                if (! $profile || ! $profile->is_active) continue;
                $components = DB::table('salary_components')->where('user_id', $staff->id)->where('effective_from', '<=', $period->end_date)->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $period->start_date))->where(fn ($query) => $query->whereNull('payroll_period_id')->orWhere('payroll_period_id', $periodId))->get()->map(fn ($row) => (array) $row)->all();
                $calculated = $this->calculate((array) $profile, $components, $rule);
                $calculated['snapshot'] += ['staff_name' => $staff->name, 'employee_no' => $staff->employee_no, 'department' => $staff->department?->name];
                $calculated['snapshot'] = json_encode($calculated['snapshot']);
                DB::table('payroll_entries')->updateOrInsert(['user_id' => $staff->id, 'payroll_period_id' => $periodId], $calculated + ['created_at' => now(), 'updated_at' => now()]); $count++; $included[] = $staff->id;
            }
            DB::table('payroll_entries')->where('payroll_period_id', $periodId)->whereNotIn('user_id', $included)->delete();
            Audit::record('payroll_calculated', null, [], ['period_id' => $periodId, 'entries' => $count], null, 'Draft payroll calculated');
            return $count;
        });
    }
    public function recordPayment(int $entryId, array $data, User $actor): void
    {
        DB::transaction(function () use ($entryId, $data, $actor) {
            $entry = DB::table('payroll_entries')->where('id', $entryId)->lockForUpdate()->first();
            $period = DB::table('payroll_periods')->find($entry->payroll_period_id);
            abort_unless($period->status === 'approved', 422, 'Approve and lock payroll before recording payments.');
            $amount = Money::fromMajor($data['amount']);
            $kind = $data['kind'];
            $snapshot = json_decode($entry->snapshot, true);
            $limit = match ($kind) { 'salary' => $entry->net_minor + (int) DB::table('payroll_adjustments')->where('payroll_entry_id', $entryId)->sum('net_delta_minor'), 'employer' => $entry->employer_minor, 'statutory' => $snapshot['employee_pension_minor'] + $snapshot['paye_minor'] };
            $paid = (int) DB::table('salary_payments')->where('payroll_entry_id', $entryId)->where('kind', $kind)->sum('amount_minor');
            abort_unless($amount > 0 && $amount <= $limit - $paid, 422, 'Payment exceeds the outstanding obligation.');
            abort_if(DB::table('salary_payments')->where('idempotency_key', $data['idempotency_key'])->exists(), 422, 'This salary payment has already been recorded.');
            foreach (['salary:'.$data['idempotency_key'], 'salaryref:'.hash('sha256', $entryId.'|'.$kind.'|'.$data['method'].'|'.$data['reference'])] as $claim) abort_unless(DB::table('payment_claims')->insertOrIgnore(['key' => $claim, 'created_at' => now()]), 422, 'Duplicate salary payment submission or reference.');
            $finance = app(FinanceService::class);
            $category = \App\Models\ExpenseCategory::firstOrCreate(['name' => 'Payroll payments']);
            $expense = $finance->recordExpense(['expense_category_id' => $category->id, 'expense_date' => $data['payment_date'], 'payee' => User::find($entry->user_id)->name, 'amount_minor' => $amount, 'payment_method' => $data['method'], 'transaction_reference' => $data['reference'], 'description' => 'Payroll '.$kind.' payment · '.$period->name.' · entry #'.$entryId], $actor);
            $finance->approveExpense($expense, $actor);
            DB::table('salary_payments')->insert(['payroll_entry_id' => $entryId, 'kind' => $kind, 'amount_minor' => $amount, 'payment_date' => $data['payment_date'], 'method' => $data['method'], 'reference' => $data['reference'], 'idempotency_key' => $data['idempotency_key'], 'expense_id' => $expense->id, 'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('payroll_payment_recorded', $expense, [], ['entry_id' => $entryId, 'kind' => $kind, 'amount_minor' => $amount], null, 'Actually paid payroll expenditure posted once');
        });
    }
}

