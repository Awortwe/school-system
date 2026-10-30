<?php

namespace App\Services;

use App\Models\CashbookEntry;
use App\Models\ClassSection;
use App\Models\Expense;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\OtherIncome;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentLedgerEntry;
use App\Models\Term;
use App\Models\User;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class FinanceService
{
    public function currentBalance(Student $student): int
    {
        return (int) (StudentLedgerEntry::where('student_id', $student->id)
            ->orderByDesc('id')
            ->value('balance_minor') ?? 0);
    }

    public function creditBalance(Student $student): int
    {
        return max(0, -$this->currentBalance($student));
    }

    public function postLedger(Student $student, array $data): StudentLedgerEntry
    {
        $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

        $last = StudentLedgerEntry::where('student_id', $student->id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $debit = (int) ($data['debit_minor'] ?? 0);
        $credit = (int) ($data['credit_minor'] ?? 0);

        if ($debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0)) {
            throw ValidationException::withMessages(['amount' => 'A ledger entry cannot be both a debit and a credit.']);
        }

        $balance = (int) ($last?->balance_minor ?? 0) + $debit - $credit;

        return StudentLedgerEntry::create([
            'student_id' => $student->id,
            'academic_year_id' => $data['academic_year_id'] ?? null,
            'term_id' => $data['term_id'] ?? null,
            'entry_date' => $data['entry_date'] ?? now()->toDateString(),
            'type' => $data['type'],
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'],
            'debit_minor' => $debit,
            'credit_minor' => $credit,
            'balance_minor' => $balance,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'recorded_by' => $data['recorded_by'] ?? null,
        ]);
    }

    public function invoiceForStudent(Student $student, Term $term, ?string $category = null, ?User $user = null): Invoice
    {
        $category ??= $student->category ?: 'day';

        $structure = $this->feeStructureFor($student, $term, $category);

        if (! $structure) {
            throw ValidationException::withMessages([
                'fee_structure' => "No active {$category} fee structure is configured for {$student->fullName()} in {$term->name}.",
            ]);
        }

        return $this->createInvoice($student, $term, $structure, $user);
    }

    public function feeStructureFor(Student $student, Term $term, string $category): ?FeeStructure
    {
        $section = $student->currentSection;
        $levelId = $section?->schoolClass?->level_id;

        return FeeStructure::with('items.feeItem')
            ->where('term_id', $term->id)
            ->where('academic_year_id', $term->academic_year_id)
            ->where('status', 'active')
            ->whereIn('category', [$category, 'all'])
            ->where(function ($query) use ($levelId) {
                $query->whereNull('level_id');
                if ($levelId) {
                    $query->orWhere('level_id', $levelId);
                }
            })
            ->where(function ($query) use ($section) {
                $query->whereNull('school_class_id');
                if ($section?->school_class_id) {
                    $query->orWhere('school_class_id', $section->school_class_id);
                }
            })
            ->get()
            ->sortByDesc(fn (FeeStructure $structure) => ((int) ($structure->school_class_id !== null)) * 2 + ((int) ($structure->level_id !== null)))
            ->first();
    }

    public function createInvoice(Student $student, Term $term, FeeStructure $structure, ?User $user = null, ?array $items = null, ?string $issueDate = null, ?int $discountMinor = null, ?string $notes = null): Invoice
    {
        $issueDate = $issueDate ? Carbon::parse($issueDate) : now();
        $dueDays = (int) Setting::get('invoice_due_days', 14);

        $sourceItems = $items ?? $structure->items->map(fn ($item) => [
            'fee_item_id' => $item->fee_item_id,
            'description' => $item->feeItem?->name ?? 'Fee',
            'amount_minor' => (int) $item->amount_minor,
        ])->all();

        if (empty($sourceItems)) {
            throw ValidationException::withMessages(['items' => 'The selected fee structure has no fee items.']);
        }

        $assignment = StudentFeeAssignment::where('student_id', $student->id)->where('term_id', $term->id)->first();
        $discountMinor ??= (int) ($assignment?->discount_minor ?? 0);

        $subtotal = collect($sourceItems)->sum(fn ($item) => (int) $item['amount_minor']);

        if ($subtotal <= 0) {
            throw ValidationException::withMessages(['items' => 'The invoice total must be greater than zero.']);
        }

        $discountMinor = max(0, min($discountMinor, $subtotal));
        $total = $subtotal - $discountMinor;

        return DB::transaction(function () use ($student, $term, $structure, $user, $sourceItems, $issueDate, $dueDays, $subtotal, $discountMinor, $total, $notes) {
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

            $existing = Invoice::where('student_id', $student->id)
                ->where('term_id', $term->id)
                ->where('type', 'termly')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'invoice' => "A termly invoice already exists for {$student->fullName()} for {$term->name} ({$existing->invoice_no}).",
                ]);
            }

            $transport = app(TransportService::class)->charges($student, $term);
            $sourceItems = array_merge($sourceItems, $transport);
            $transportTotal = array_sum(array_column($transport, 'amount_minor'));
            $subtotal += $transportTotal; $total += $transportTotal;

            $invoice = Invoice::create([
                'invoice_no' => DocumentNumberService::nextInvoice((int) $issueDate->format('Y')),
                'student_id' => $student->id,
                'term_id' => $term->id,
                'academic_year_id' => $term->academic_year_id,
                'class_section_id' => $student->current_section_id,
                'fee_structure_id' => $structure->id,
                'type' => 'termly',
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $issueDate->copy()->addDays($dueDays)->toDateString(),
                'status' => $total > 0 ? 'issued' : 'paid',
                'subtotal_minor' => $subtotal,
                'discount_minor' => $discountMinor,
                'total_minor' => $total,
                'paid_minor' => 0,
                'balance_minor' => $total,
                'notes' => $notes,
                'created_by' => $user?->id,
            ]);

            foreach ($sourceItems as $item) {
                $amount = (int) $item['amount_minor'];

                $invoice->items()->create([
                    'fee_item_id' => $item['fee_item_id'] ?? null,
                    'description' => $item['description'],
                    'amount_minor' => $amount,
                    'discount_minor' => 0,
                    'net_minor' => $amount,
                ]);
                if (isset($item['transport_enrollment_id'])) DB::table('transport_billings')->insert(['transport_enrollment_id' => $item['transport_enrollment_id'], 'term_id' => $term->id, 'invoice_id' => $invoice->id, 'amount_minor' => $amount, 'created_at' => now(), 'updated_at' => now()]);
            }

            if ($total > 0) {
                $this->postLedger($student, [
                    'academic_year_id' => $term->academic_year_id,
                    'term_id' => $term->id,
                    'entry_date' => $issueDate->toDateString(),
                    'type' => 'invoice',
                    'reference' => $invoice->invoice_no,
                    'description' => 'Termly invoice issued for '.$term->name,
                    'debit_minor' => $total,
                    'source_type' => Invoice::class,
                    'source_id' => $invoice->id,
                    'recorded_by' => $user?->id,
                ]);
            }

            // Prior confirmed overpayments can settle a later invoice without a second ledger credit.
            foreach (Payment::where('student_id', $student->id)->where('status', 'confirmed')->where('unallocated_minor', '>', 0)->orderBy('id')->lockForUpdate()->get() as $creditPayment) {
                $invoice->refresh(); if ($invoice->balance_minor <= 0) break;
                $allocation = min($creditPayment->unallocated_minor, $invoice->balance_minor);
                $this->allocate($creditPayment, $invoice, $allocation);
                $creditPayment->update(['allocated_minor' => $creditPayment->allocated_minor + $allocation, 'unallocated_minor' => $creditPayment->unallocated_minor - $allocation]);
            }

            Audit::record('invoice_created', $invoice, [], $invoice->only(['invoice_no', 'total_minor']), null, 'Termly invoice created');

            return $invoice->refresh();
        });
    }

    public function generateInvoices(ClassSection $section, Term $term, ?string $category, User $user): array
    {
        $students = Student::where('current_section_id', $section->id)->where('status', 'active')->orderBy('last_name')->get();

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($students as $student) {
            try {
                $this->invoiceForStudent($student, $term, $category ?: $student->category, $user);
                $created++;
            } catch (ValidationException $exception) {
                $skipped++;
                $errors[] = $student->fullName().': '.collect($exception->errors())->flatten()->first();
            } catch (Throwable $exception) {
                $errors[] = $student->fullName().': '.$exception->getMessage();
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function recordPayment(Student $student, array $data, User $user): Payment
    {
        $amount = (int) ($data['amount_minor'] ?? 0);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount_minor' => 'Payment amount must be greater than zero.']);
        }

        $paymentDate = Carbon::parse($data['payment_date'] ?? now())->startOfDay();

        return DB::transaction(function () use ($student, $data, $user, $amount, $paymentDate) {
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

            if (Payment::where('idempotency_key', $data['idempotency_key'])->exists()) {
                throw ValidationException::withMessages(['idempotency_key' => 'This payment has already been submitted.']);
            }

            $claims = ['submission:'.$data['idempotency_key']];
            if (! empty($data['transaction_reference'])) {
                if (Payment::where('method', $data['method'])->where('transaction_reference', $data['transaction_reference'])->exists()) throw ValidationException::withMessages(['transaction_reference' => 'This payment reference has already been recorded.']);
                $claims[] = 'reference:'.hash('sha256', $data['method'].'|'.$data['transaction_reference']);
            }
            foreach ($claims as $claim) {
                if (! DB::table('payment_claims')->insertOrIgnore(['key' => $claim, 'created_at' => now()])) throw ValidationException::withMessages(['payment' => 'This payment submission or transaction reference has already been recorded.']);
            }

            $term = isset($data['term_id']) && $data['term_id']
                ? Term::findOrFail($data['term_id'])
                : Term::where('academic_year_id', $data['academic_year_id'] ?? null)->where('is_current', true)->first();

            $payment = Payment::create([
                'receipt_no' => DocumentNumberService::nextReceipt((int) $paymentDate->format('Y')),
                'student_id' => $student->id,
                'term_id' => $term?->id,
                'academic_year_id' => $term?->academic_year_id ?? ($data['academic_year_id'] ?? null),
                'payment_date' => $paymentDate->toDateString(),
                'amount_minor' => $amount,
                'allocated_minor' => 0,
                'unallocated_minor' => $amount,
                'method' => $data['method'],
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'confirmed',
                'idempotency_key' => $data['idempotency_key'],
                'attachment_path' => $data['attachment_path'] ?? null,
                'recorded_by' => $user->id,
            ]);

            $remaining = $amount;
            $allocations = $data['allocations'] ?? [];

            if (! empty($allocations)) {
                $invoices = Invoice::whereIn('id', array_keys($allocations))
                    ->where('student_id', $student->id)
                    ->where('status', '!=', 'void')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($allocations as $invoiceId => $requested) {
                    $requested = (int) $requested;
                    $invoice = $invoices->get((int) $invoiceId);

                    if (! $invoice) {
                        throw ValidationException::withMessages(['allocations' => 'One of the selected invoices does not belong to this student.']);
                    }

                    if ($requested <= 0) {
                        continue;
                    }

                    if ($requested > $remaining) {
                        throw ValidationException::withMessages(['allocations' => 'Allocations cannot exceed the payment amount.']);
                    }

                    if ($requested > $invoice->balance_minor) {
                        throw ValidationException::withMessages(['allocations' => "Allocation to {$invoice->invoice_no} exceeds its outstanding balance."]);
                    }

                    $this->allocate($payment, $invoice, $requested);
                    $remaining -= $requested;
                }
            } else {
                $invoices = Invoice::where('student_id', $student->id)
                    ->where('status', '!=', 'void')
                    ->where('balance_minor', '>', 0)
                    ->orderBy('due_date')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($invoices as $invoice) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $allocation = min($remaining, $invoice->balance_minor);
                    $this->allocate($payment, $invoice, $allocation);
                    $remaining -= $allocation;
                }
            }

            $payment->update([
                'allocated_minor' => $amount - $remaining,
                'unallocated_minor' => $remaining,
            ]);

            $this->postLedger($student, [
                'academic_year_id' => $payment->academic_year_id,
                'term_id' => $payment->term_id,
                'entry_date' => $paymentDate->toDateString(),
                'type' => 'payment',
                'reference' => $payment->receipt_no,
                'description' => 'Payment received via '.str_replace('_', ' ', $payment->method),
                'credit_minor' => $amount,
                'source_type' => Payment::class,
                'source_id' => $payment->id,
                'recorded_by' => $user->id,
            ]);

            $payment->update(['receipt_snapshot' => [
                'school' => Setting::school(), 'student_name' => $student->fullName(), 'admission_no' => $student->admission_no,
                'class' => $student->currentSection?->displayName(), 'year' => $term?->academicYear?->name,
                'term' => $term?->name, 'recorded_by' => $user->name, 'balance_minor' => $this->currentBalance($student),
                'unallocated_minor' => $remaining,
                'allocations' => $payment->allocations()->with('invoice.items')->get()->map(fn ($allocation) => ['invoice_no' => $allocation->invoice?->invoice_no, 'amount_minor' => $allocation->amount_minor, 'items' => $allocation->invoice?->items->pluck('description')->all() ?? []])->all(),
            ]]);

            CashbookEntry::create([
                'entry_date' => $paymentDate->toDateString(),
                'type' => 'fee_payment',
                'reference' => $payment->receipt_no,
                'description' => 'School fees payment from '.$student->fullName(),
                'method' => $payment->method,
                'inflow_minor' => $amount,
                'source_type' => Payment::class,
                'source_id' => $payment->id,
                'recorded_by' => $user->id,
            ]);

            Audit::record('payment_recorded', $payment, [], $payment->only(['receipt_no', 'amount_minor', 'method']), null, 'Payment recorded');

            return $payment->refresh();
        });
    }

    private function allocate(Payment $payment, Invoice $invoice, int $amount): void
    {
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount_minor' => $amount,
        ]);

        $this->recalculateInvoice($invoice);
    }

    public function recalculateInvoice(Invoice $invoice): Invoice
    {
        $invoice->refresh();

        if ($invoice->status === 'void') {
            return $invoice;
        }

        $paid = (int) PaymentAllocation::where('invoice_id', $invoice->id)->whereNull('reversed_at')->sum('amount_minor');
        $total = max(0, (int) $invoice->subtotal_minor - (int) $invoice->discount_minor);
        $balance = max(0, $total - $paid);

        $status = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued');

        $invoice->update([
            'total_minor' => $total,
            'paid_minor' => $paid,
            'balance_minor' => $balance,
            'status' => $status,
        ]);

        return $invoice->refresh();
    }

    public function reversePayment(Payment $payment, User $user, string $reason): Payment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reversal reason is required.']);
        }

        return DB::transaction(function () use ($payment, $user, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $student = Student::whereKey($payment->student_id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== 'confirmed') {
                throw ValidationException::withMessages(['payment' => 'Only confirmed payments can be reversed.']);
            }

            $allocations = $payment->allocations()->whereNull('reversed_at')->lockForUpdate()->get();

            foreach ($allocations as $allocation) {
                $allocation->update(['reversed_at' => now()]);
                $this->recalculateInvoice($allocation->invoice);
            }

            $payment->update([
                'status' => 'reversed',
                'reversed_by' => $user->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);

            $this->postLedger($student, [
                'academic_year_id' => $payment->academic_year_id,
                'term_id' => $payment->term_id,
                'entry_date' => now()->toDateString(),
                'type' => 'payment_reversal',
                'reference' => $payment->receipt_no,
                'description' => 'Payment reversed: '.$reason,
                'debit_minor' => $payment->amount_minor,
                'source_type' => Payment::class,
                'source_id' => $payment->id,
                'recorded_by' => $user->id,
            ]);

            CashbookEntry::create([
                'entry_date' => now()->toDateString(),
                'type' => 'payment_reversal',
                'reference' => $payment->receipt_no,
                'description' => 'Reversal of payment from '.$student->fullName(),
                'method' => $payment->method,
                'outflow_minor' => $payment->amount_minor,
                'source_type' => Payment::class,
                'source_id' => $payment->id,
                'recorded_by' => $user->id,
            ]);

            Audit::record('payment_reversed', $payment, ['status' => 'confirmed'], ['status' => 'reversed'], $reason, 'Payment reversed');

            return $payment->refresh();
        });
    }

    public function requestAdjustment(Invoice $invoice, array $data, User $user): InvoiceAdjustment
    {
        if ($invoice->status === 'void') {
            throw ValidationException::withMessages(['invoice' => 'A void invoice cannot be adjusted.']);
        }

        $amount = (int) ($data['amount_minor'] ?? 0);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount_minor' => 'Adjustment amount must be greater than zero.']);
        }

        return InvoiceAdjustment::create([
            'invoice_id' => $invoice->id,
            'type' => $data['type'] ?? 'discount',
            'amount_minor' => $amount,
            'reason' => $data['reason'],
            'status' => 'pending',
            'requested_by' => $user->id,
        ]);
    }

    public function approveAdjustment(InvoiceAdjustment $adjustment, User $user): InvoiceAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user) {
            $adjustment = InvoiceAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if ($adjustment->status !== 'pending') {
                throw ValidationException::withMessages(['adjustment' => 'Only pending adjustments can be approved.']);
            }

            $invoice = Invoice::whereKey($adjustment->invoice_id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === 'void') {
                throw ValidationException::withMessages(['invoice' => 'A void invoice cannot be adjusted.']);
            }

            if ($adjustment->amount_minor > $invoice->balance_minor) {
                throw ValidationException::withMessages(['amount_minor' => 'The adjustment cannot exceed the invoice outstanding balance.']);
            }

            $invoice->update([
                'discount_minor' => $invoice->discount_minor + $adjustment->amount_minor,
            ]);

            $this->recalculateInvoice($invoice);

            $student = Student::whereKey($invoice->student_id)->lockForUpdate()->firstOrFail();

            $this->postLedger($student, [
                'academic_year_id' => $invoice->academic_year_id,
                'term_id' => $invoice->term_id,
                'entry_date' => now()->toDateString(),
                'type' => 'adjustment',
                'reference' => $invoice->invoice_no,
                'description' => 'Invoice adjustment: '.$adjustment->reason,
                'credit_minor' => $adjustment->amount_minor,
                'source_type' => InvoiceAdjustment::class,
                'source_id' => $adjustment->id,
                'recorded_by' => $user->id,
            ]);

            $adjustment->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            Audit::record('invoice_adjustment_approved', $adjustment, ['status' => 'pending'], ['status' => 'approved'], $adjustment->reason, 'Invoice adjustment approved');

            return $adjustment->refresh();
        });
    }

    public function rejectAdjustment(InvoiceAdjustment $adjustment, User $user, string $reason): InvoiceAdjustment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }

        if ($adjustment->status !== 'pending') {
            throw ValidationException::withMessages(['adjustment' => 'Only pending adjustments can be rejected.']);
        }

        $adjustment->update([
            'status' => 'rejected',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        Audit::record('invoice_adjustment_rejected', $adjustment, ['status' => 'pending'], ['status' => 'rejected'], $reason, 'Invoice adjustment rejected');

        return $adjustment->refresh();
    }

    public function approveExpense(Expense $expense, User $user): Expense
    {
        return DB::transaction(function () use ($expense, $user) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->status !== 'pending') {
                throw ValidationException::withMessages(['expense' => 'Only pending expenses can be approved.']);
            }

            $expense->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            CashbookEntry::create([
                'entry_date' => $expense->expense_date->toDateString(),
                'type' => 'expense',
                'reference' => $expense->expense_no,
                'description' => $expense->description,
                'method' => $expense->payment_method,
                'outflow_minor' => $expense->amount_minor,
                'source_type' => Expense::class,
                'source_id' => $expense->id,
                'recorded_by' => $user->id,
            ]);

            Audit::record('expense_approved', $expense, ['status' => 'pending'], ['status' => 'approved'], null, 'Expense approved');

            return $expense->refresh();
        });
    }

    public function rejectExpense(Expense $expense, User $user, string $reason): Expense
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }

        if ($expense->status !== 'pending') {
            throw ValidationException::withMessages(['expense' => 'Only pending expenses can be rejected.']);
        }

        $expense->update([
            'status' => 'rejected',
            'approved_by' => $user->id,
            'rejection_reason' => $reason,
        ]);

        Audit::record('expense_rejected', $expense, ['status' => 'pending'], ['status' => 'rejected'], $reason, 'Expense rejected');

        return $expense->refresh();
    }

    public function recordExpense(array $data, User $user): Expense
    {
        $amount = (int) ($data['amount_minor'] ?? 0);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount_minor' => 'Expense amount must be greater than zero.']);
        }

        $expenseDate = Carbon::parse($data['expense_date'] ?? now());

        return DB::transaction(function () use ($data, $user, $amount, $expenseDate) {
            $expense = Expense::create([
                'expense_no' => DocumentNumberService::nextExpense((int) $expenseDate->format('Y')),
                'expense_category_id' => $data['expense_category_id'] ?? null,
                'expense_date' => $expenseDate->toDateString(),
                'payee' => $data['payee'],
                'amount_minor' => $amount,
                'payment_method' => $data['payment_method'],
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'description' => $data['description'],
                'attachment_path' => $data['attachment_path'] ?? null,
                'status' => 'pending',
                'created_by' => $user->id,
            ]);

            Audit::record('expense_created', $expense, [], $expense->only(['expense_no', 'amount_minor']), null, 'Expense recorded');

            return $expense;
        });
    }

    public function recordOtherIncome(array $data, User $user): OtherIncome
    {
        $amount = (int) ($data['amount_minor'] ?? 0);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount_minor' => 'Income amount must be greater than zero.']);
        }

        $incomeDate = Carbon::parse($data['income_date'] ?? now());

        return DB::transaction(function () use ($data, $user, $amount, $incomeDate) {
            $income = OtherIncome::create([
                'income_no' => DocumentNumberService::nextIncome((int) $incomeDate->format('Y')),
                'income_category_id' => $data['income_category_id'] ?? null,
                'income_date' => $incomeDate->toDateString(),
                'source' => $data['source'],
                'amount_minor' => $amount,
                'payment_method' => $data['payment_method'],
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'description' => $data['description'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'recorded_by' => $user->id,
            ]);

            CashbookEntry::create([
                'entry_date' => $incomeDate->toDateString(),
                'type' => 'other_income',
                'reference' => $income->income_no,
                'description' => $income->source,
                'method' => $income->payment_method,
                'inflow_minor' => $amount,
                'source_type' => OtherIncome::class,
                'source_id' => $income->id,
                'recorded_by' => $user->id,
            ]);

            Audit::record('income_recorded', $income, [], $income->only(['income_no', 'amount_minor']), null, 'Other income recorded');

            return $income;
        });
    }

    public function refund(Student $student, array $data, User $user): Refund
    {
        $amount = (int) ($data['amount_minor'] ?? 0);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount_minor' => 'Refund amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($student, $data, $user, $amount) {
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $credit = $this->creditBalance($student);

            if ($amount > $credit) {
                throw ValidationException::withMessages(['amount_minor' => 'Refund cannot exceed the available credit balance of '.number_format($credit / 100, 2).'.']);
            }

            $refundDate = Carbon::parse($data['refund_date'] ?? now());

            $refund = Refund::create([
                'refund_no' => DocumentNumberService::nextRefund((int) $refundDate->format('Y')),
                'payment_id' => $data['payment_id'] ?? null,
                'student_id' => $student->id,
                'amount_minor' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'reason' => $data['reason'],
                'status' => 'completed',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'processed_by' => $user->id,
            ]);

            $this->postLedger($student, [
                'entry_date' => $refundDate->toDateString(),
                'type' => 'refund',
                'reference' => $refund->refund_no,
                'description' => 'Refund: '.$refund->reason,
                'debit_minor' => $amount,
                'source_type' => Refund::class,
                'source_id' => $refund->id,
                'recorded_by' => $user->id,
            ]);

            CashbookEntry::create([
                'entry_date' => $refundDate->toDateString(),
                'type' => 'refund',
                'reference' => $refund->refund_no,
                'description' => 'Refund to '.$student->fullName(),
                'method' => $refund->method,
                'outflow_minor' => $amount,
                'source_type' => Refund::class,
                'source_id' => $refund->id,
                'recorded_by' => $user->id,
            ]);

            Audit::record('refund_processed', $refund, [], $refund->only(['refund_no', 'amount_minor']), $refund->reason, 'Refund processed');

            return $refund;
        });
    }
}
