<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReceiptReprint;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use App\Services\FinanceService;
use App\Support\Audit;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PaymentController extends Controller
{
    public function __construct(private readonly FinanceService $finance)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'method', 'from', 'to']);

        $payments = Payment::with(['student.currentSection.schoolClass', 'term'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('receipt_no', 'like', "%{$search}%")
                        ->orWhere('transaction_reference', 'like', "%{$search}%")
                        ->orWhereHas('student', function ($query) use ($search) {
                            $query->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('admission_no', 'like', "%{$search}%");
                        });
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['method'] ?? null, fn ($query, $method) => $query->where('method', $method))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('payment_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('payment_date', '<=', $date))
            ->latest('payment_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Payment $payment) => [
                'id' => $payment->id,
                'receipt_no' => $payment->receipt_no,
                'student' => $payment->student?->fullName(),
                'admission_no' => $payment->student?->admission_no,
                'class' => $payment->student?->currentSection?->displayName(),
                'payment_date' => $payment->payment_date?->toDateString(),
                'amount_minor' => $payment->amount_minor,
                'allocated_minor' => $payment->allocated_minor,
                'unallocated_minor' => $payment->unallocated_minor,
                'method' => $payment->method,
                'reference' => $payment->transaction_reference,
                'status' => $payment->status,
            ]);

        return Inertia::render('Finance/Payments/Index', [
            'payments' => $payments,
            'filters' => $filters,
            'methods' => $this->methods(),
            'stats' => [
                'confirmed' => (int) Payment::where('status', 'confirmed')->sum('amount_minor'),
                'today' => (int) Payment::where('status', 'confirmed')->whereDate('payment_date', now())->sum('amount_minor'),
                'reversed' => (int) Payment::where('status', 'reversed')->sum('amount_minor'),
                'count' => Payment::where('status', 'confirmed')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $student = $request->integer('student_id') ? Student::with('currentSection.schoolClass')->find($request->integer('student_id')) : null;

        $invoices = $student
            ? Invoice::where('student_id', $student->id)
                ->where('status', '!=', 'void')
                ->where('balance_minor', '>', 0)
                ->orderBy('due_date')
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no,
                    'due_date' => $invoice->due_date?->toDateString(),
                    'total_minor' => $invoice->total_minor,
                    'paid_minor' => $invoice->paid_minor,
                    'balance_minor' => $invoice->balance_minor,
                ])
            : collect();

        return Inertia::render('Finance/Payments/Create', [
            'students' => Student::with('currentSection.schoolClass')
                ->orderBy('last_name')
                ->get()
                ->map(fn (Student $student) => [
                    'id' => $student->id,
                    'name' => $student->fullName(),
                    'admission_no' => $student->admission_no,
                    'class' => $student->currentSection?->displayName(),
                    'balance_minor' => $this->finance->currentBalance($student),
                ]),
            'student' => $student ? [
                'id' => $student->id,
                'name' => $student->fullName(),
                'admission_no' => $student->admission_no,
                'class' => $student->currentSection?->displayName(),
                'balance_minor' => $this->finance->currentBalance($student),
            ] : null,
            'invoices' => $invoices,
            'terms' => Term::with('academicYear')->orderByDesc('academic_year_id')->orderBy('sequence')->get()->map(fn (Term $term) => [
                'id' => $term->id,
                'name' => ($term->academicYear?->name ? $term->academicYear->name.' - ' : '').$term->name,
            ]),
            'methods' => $this->methods(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'term_id' => ['nullable', 'exists:terms,id'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in($this->methodValues())],
            'transaction_reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
            'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $allocations = [];

        foreach ($data['allocations'] ?? [] as $invoiceId => $amount) {
            if ((float) $amount > 0) {
                $allocations[$invoiceId] = Money::fromMajor($amount);
            }
        }

        $payment = $this->finance->recordPayment(
            Student::findOrFail($data['student_id']),
            [
                'term_id' => $data['term_id'] ?? null,
                'payment_date' => $data['payment_date'],
                'amount_minor' => Money::fromMajor($data['amount']),
                'method' => $data['method'],
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?: (string) Str::uuid(),
                'allocations' => $allocations,
                'attachment_path' => $request->hasFile('proof') ? $request->file('proof')->store('payment-proofs', 'local') : null,
            ],
            $request->user(),
        );

        return redirect()->route('finance.payments.show', $payment)->with('success', 'Payment '.$payment->receipt_no.' recorded.');
    }

    public function show(Payment $payment): Response
    {
        $payment->load(['student.currentSection.schoolClass', 'term.academicYear', 'allocations.invoice', 'recordedBy', 'reversedBy']);

        return Inertia::render('Finance/Payments/Show', [
            'payment' => [
                'id' => $payment->id,
                'receipt_no' => $payment->receipt_no,
                'student' => $payment->student?->fullName(),
                'admission_no' => $payment->student?->admission_no,
                'student_id' => $payment->student_id,
                'class' => $payment->student?->currentSection?->displayName(),
                'term' => $payment->term?->name,
                'year' => $payment->term?->academicYear?->name,
                'payment_date' => $payment->payment_date?->toDateString(),
                'amount_minor' => $payment->amount_minor,
                'allocated_minor' => $payment->allocated_minor,
                'unallocated_minor' => $payment->unallocated_minor,
                'method' => $payment->method,
                'transaction_reference' => $payment->transaction_reference,
                'notes' => $payment->notes,
                'status' => $payment->status,
                'recorded_by' => $payment->recordedBy?->name,
                'reversed_by' => $payment->reversedBy?->name,
                'reversed_at' => $payment->reversed_at?->toDateTimeString(),
                'reversal_reason' => $payment->reversal_reason,
                'allocations' => $payment->allocations->whereNull('reversed_at')->map(fn ($allocation) => [
                    'id' => $allocation->id,
                    'invoice_id' => $allocation->invoice_id,
                    'invoice_no' => $allocation->invoice?->invoice_no,
                    'amount_minor' => $allocation->amount_minor,
                ])->values(),
            ],
        ]);
    }

    public function reverse(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->finance->reversePayment($payment, $request->user(), $data['reason']);

        return back()->with('success', 'Payment reversed. The reversal has been recorded in the ledger and cashbook.');
    }

    public function receipt(Request $request, Payment $payment): HttpResponse
    {
        $request->validate(['format' => ['nullable', 'in:a4,thermal'], 'width' => ['nullable', 'in:58,80']]);
        $payment->load(['student.currentSection.schoolClass', 'allocations.invoice', 'recordedBy']);

        $isReprint = ReceiptReprint::where('payment_id', $payment->id)->exists();

        {
            ReceiptReprint::create([
                'payment_id' => $payment->id,
                'reprinted_by' => auth()->id(),
                'ip_address' => request()->ip(),
                'reprinted_at' => now(),
            ]);
        }

        Audit::record('receipt_printed', $payment, [], ['receipt_no' => $payment->receipt_no, 'copy' => $isReprint], null, 'Payment receipt printed');

        if ($request->input('format') === 'thermal') return response()->view('print.thermal-receipt', ['payment' => $payment, 'school' => $payment->receipt_snapshot['school'] ?? Setting::school(), 'copy' => $isReprint, 'width' => (int) $request->input('width', Setting::get('thermal_width', 80))]);

        return Pdf::loadView('pdf.receipt', [
            'payment' => $payment,
            'school' => $payment->receipt_snapshot['school'] ?? Setting::school(),
            'copy' => $isReprint,
            'is_reversed' => $payment->status === 'reversed',
        ])->setPaper('a4', 'portrait')->setOption([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ])->{$request->boolean('download') ? 'download' : 'stream'}('receipt-'.$payment->receipt_no.'.pdf');
    }

    private function methods(): array
    {
        $labels = [
            'cash' => 'Cash',
            'momo' => 'Mobile Money',
            'bank_transfer' => 'Bank Transfer',
            'cheque' => 'Cheque',
        ];

        return collect(Setting::get('payment_methods', ['cash', 'momo', 'bank_transfer', 'cheque']))
            ->map(fn ($method) => ['value' => $method, 'label' => $labels[$method] ?? ucfirst(str_replace('_', ' ', $method))])
            ->values()
            ->all();
    }

    private function methodValues(): array
    {
        return collect($this->methods())->pluck('value')->all();
    }
}
