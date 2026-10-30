<?php

namespace App\Http\Controllers;

use App\Models\ClassSection;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use App\Services\FinanceService;
use App\Support\Audit;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InvoiceController extends Controller
{
    public function __construct(private readonly FinanceService $finance)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'term_id']);

        $invoices = Invoice::with(['student.currentSection.schoolClass', 'term'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('invoice_no', 'like', "%{$search}%")
                        ->orWhereHas('student', function ($query) use ($search) {
                            $query->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('admission_no', 'like', "%{$search}%");
                        });
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['term_id'] ?? null, fn ($query, $term) => $query->where('term_id', $term))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'student' => $invoice->student?->fullName(),
                'admission_no' => $invoice->student?->admission_no,
                'class' => $invoice->student?->currentSection?->displayName(),
                'term' => $invoice->term?->name,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total_minor' => $invoice->total_minor,
                'paid_minor' => $invoice->paid_minor,
                'balance_minor' => $invoice->balance_minor,
                'status' => $invoice->status,
                'overdue' => $invoice->balance_minor > 0 && $invoice->due_date?->isPast(),
            ]);

        return Inertia::render('Finance/Invoices/Index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'terms' => $this->terms(),
            'sections' => ClassSection::with('schoolClass.level')->get()->map(fn (ClassSection $section) => [
                'id' => $section->id,
                'name' => $section->displayName(),
            ]),
            'stats' => [
                'outstanding' => (int) Invoice::whereIn('status', ['issued', 'partially_paid'])->sum('balance_minor'),
                'overdue' => (int) Invoice::whereIn('status', ['issued', 'partially_paid'])->whereDate('due_date', '<', now())->sum('balance_minor'),
                'invoiced' => (int) Invoice::whereYear('issue_date', now()->year)->sum('total_minor'),
                'pending_adjustments' => InvoiceAdjustment::where('status', 'pending')->count(),
            ],
            'adjustments' => InvoiceAdjustment::with(['invoice.student'])
                ->where('status', 'pending')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (InvoiceAdjustment $adjustment) => [
                    'id' => $adjustment->id,
                    'invoice_no' => $adjustment->invoice?->invoice_no,
                    'student' => $adjustment->invoice?->student?->fullName(),
                    'type' => $adjustment->type,
                    'amount_minor' => $adjustment->amount_minor,
                    'reason' => $adjustment->reason,
                    'created_at' => $adjustment->created_at?->toDateTimeString(),
                ]),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Finance/Invoices/Create', [
            'students' => Student::with('currentSection.schoolClass')
                ->where('status', 'active')
                ->orderBy('last_name')
                ->get()
                ->map(fn (Student $student) => [
                    'id' => $student->id,
                    'name' => $student->fullName(),
                    'admission_no' => $student->admission_no,
                    'class' => $student->currentSection?->displayName(),
                    'category' => $student->category,
                ]),
            'terms' => $this->terms(),
            'selectedStudentId' => $request->integer('student_id') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'term_id' => ['required', 'exists:terms,id'],
            'category' => ['nullable', Rule::in(['day', 'boarding'])],
            'issue_date' => ['nullable', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $student = Student::findOrFail($data['student_id']);
        $term = Term::findOrFail($data['term_id']);

        $invoice = $this->finance->invoiceForStudent(
            $student,
            $term,
            $data['category'] ?? $student->category,
            $request->user(),
        );

        if (! empty($data['discount'])) {
            $adjustment = $this->finance->requestAdjustment($invoice, [
                'type' => 'discount',
                'amount_minor' => Money::fromMajor($data['discount']),
                'reason' => 'Discount entered when creating invoice',
            ], $request->user());

            // Discounts follow the separate approval workflow.
        }

        return redirect()->route('finance.invoices.show', $invoice)->with('success', 'Invoice '.$invoice->invoice_no.' created.');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'class_section_id' => ['required', 'exists:class_sections,id'],
            'term_id' => ['required', 'exists:terms,id'],
            'category' => ['nullable', Rule::in(['day', 'boarding'])],
        ]);

        $result = $this->finance->generateInvoices(
            ClassSection::findOrFail($data['class_section_id']),
            Term::findOrFail($data['term_id']),
            $data['category'] ?? null,
            $request->user(),
        );

        $message = $result['created'].' invoice(s) created.';

        if ($result['skipped'] > 0) {
            $message .= ' '.$result['skipped'].' skipped.';
        }

        return back()->with($result['errors'] ? 'warning' : 'success', $message)
            ->with('bulk_errors', $result['errors']);
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load([
            'student.currentSection.schoolClass.level',
            'term.academicYear',
            'items.feeItem',
            'adjustments.requestedBy',
            'adjustments.approvedBy',
            'allocations.payment',
        ]);

        return Inertia::render('Finance/Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'student' => $invoice->student?->fullName(),
                'admission_no' => $invoice->student?->admission_no,
                'student_id' => $invoice->student_id,
                'class' => $invoice->student?->currentSection?->displayName(),
                'term' => $invoice->term?->name,
                'year' => $invoice->term?->academicYear?->name,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'status' => $invoice->status,
                'subtotal_minor' => $invoice->subtotal_minor,
                'discount_minor' => $invoice->discount_minor,
                'total_minor' => $invoice->total_minor,
                'paid_minor' => $invoice->paid_minor,
                'balance_minor' => $invoice->balance_minor,
                'notes' => $invoice->notes,
                'items' => $invoice->items->map(fn ($item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'amount_minor' => $item->amount_minor,
                    'net_minor' => $item->net_minor,
                ]),
                'adjustments' => $invoice->adjustments->map(fn (InvoiceAdjustment $adjustment) => [
                    'id' => $adjustment->id,
                    'type' => $adjustment->type,
                    'amount_minor' => $adjustment->amount_minor,
                    'reason' => $adjustment->reason,
                    'status' => $adjustment->status,
                    'requested_by' => $adjustment->requestedBy?->name,
                    'approved_by' => $adjustment->approvedBy?->name,
                    'created_at' => $adjustment->created_at?->toDateTimeString(),
                ]),
                'allocations' => $invoice->allocations->whereNull('reversed_at')->map(fn ($allocation) => [
                    'id' => $allocation->id,
                    'payment_id' => $allocation->payment_id,
                    'receipt_no' => $allocation->payment?->receipt_no,
                    'payment_date' => $allocation->payment?->payment_date?->toDateString(),
                    'amount_minor' => $allocation->amount_minor,
                ])->values(),
            ],
        ]);
    }

    public function pdf(Invoice $invoice): HttpResponse
    {
        $invoice->load(['student.currentSection.schoolClass.level', 'term.academicYear', 'items', 'allocations.payment']);

        Audit::record('invoice_printed', $invoice, [], ['invoice_no' => $invoice->invoice_no], null, 'Invoice printed');

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'school' => Setting::school(),
        ])->setPaper('a4', 'portrait')->setOption([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ])->stream('invoice-'.$invoice->invoice_no.'.pdf');
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        if ($invoice->paid_minor > 0) {
            return back()->with('error', 'This invoice has payments. Reverse the payments before voiding it.');
        }

        if ($invoice->status === 'void') {
            return back()->with('warning', 'This invoice is already void.');
        }

        $balance = $invoice->balance_minor;
        $oldStatus = $invoice->status;

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $invoice, $data, $balance, $oldStatus) {
            Student::whereKey($invoice->student_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'void' || $invoice->paid_minor > 0) throw \Illuminate\Validation\ValidationException::withMessages(['invoice' => 'Only an unpaid, non-void invoice can be voided.']);
            $balance = $invoice->balance_minor;
            $oldStatus = $invoice->status;
            if ($balance > 0) {
                $this->finance->postLedger($invoice->student, [
                    'academic_year_id' => $invoice->academic_year_id,
                    'term_id' => $invoice->term_id,
                    'entry_date' => now()->toDateString(),
                    'type' => 'invoice_void',
                    'reference' => $invoice->invoice_no,
                    'description' => 'Invoice voided: '.$data['reason'],
                    'credit_minor' => $balance,
                    'source_type' => Invoice::class,
                    'source_id' => $invoice->id,
                    'recorded_by' => $request->user()->id,
                ]);
            }

            $invoice->update([
                'status' => 'void',
                'balance_minor' => 0,
                'notes' => trim(($invoice->notes ? $invoice->notes."\n" : '').'VOID: '.$data['reason']),
            ]);

            Audit::record('invoice_voided', $invoice, ['status' => $oldStatus], ['status' => 'void'], $data['reason'], 'Invoice voided');
        });

        return back()->with('success', 'Invoice voided.');
    }

    public function adjustment(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['discount', 'waiver', 'write_off', 'credit'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $adjustment = $this->finance->requestAdjustment($invoice, [
            'type' => $data['type'],
            'amount_minor' => Money::fromMajor($data['amount']),
            'reason' => $data['reason'],
        ], $request->user());

        if ($request->user()->can('adjustments.approve')) {
            $this->finance->approveAdjustment($adjustment, $request->user());

            return back()->with('success', 'Adjustment approved and applied.');
        }

        return back()->with('success', 'Adjustment requested. A Headteacher or Super Administrator must approve it.');
    }

    private function terms(): \Illuminate\Support\Collection
    {
        return Term::with('academicYear')->orderByDesc('academic_year_id')->orderBy('sequence')->get()->map(fn (Term $term) => [
            'id' => $term->id,
            'name' => ($term->academicYear?->name ? $term->academicYear->name.' - ' : '').$term->name,
        ]);
    }
}
