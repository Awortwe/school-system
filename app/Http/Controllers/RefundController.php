<?php

namespace App\Http\Controllers;

use App\Models\Refund;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentLedgerEntry;
use App\Services\FinanceService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RefundController extends Controller
{
    public function __construct(private readonly FinanceService $finance)
    {
    }

    public function index(): Response
    {
        $credits = StudentLedgerEntry::with('student.currentSection.schoolClass')
            ->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')->from('student_ledger_entries')->groupBy('student_id');
            })
            ->where('balance_minor', '<', 0)
            ->orderBy('balance_minor')
            ->get()
            ->map(fn (StudentLedgerEntry $entry) => [
                'student_id' => $entry->student_id,
                'name' => $entry->student?->fullName(),
                'admission_no' => $entry->student?->admission_no,
                'class' => $entry->student?->currentSection?->displayName(),
                'credit_minor' => abs($entry->balance_minor),
            ]);

        return Inertia::render('Finance/Refunds/Index', [
            'refunds' => Refund::with(['student', 'approvedBy', 'processedBy'])
                ->latest('id')
                ->paginate(20)
                ->through(fn (Refund $refund) => [
                    'id' => $refund->id,
                    'refund_no' => $refund->refund_no,
                    'student' => $refund->student?->fullName(),
                    'amount_minor' => $refund->amount_minor,
                    'method' => $refund->method,
                    'reference' => $refund->reference,
                    'reason' => $refund->reason,
                    'status' => $refund->status,
                    'approved_by' => $refund->approvedBy?->name,
                    'processed_by' => $refund->processedBy?->name,
                    'created_at' => $refund->created_at?->toDateString(),
                ]),
            'credits' => $credits,
            'methods' => $this->methods(),
            'stats' => [
                'refunded' => (int) Refund::where('status', 'completed')->sum('amount_minor'),
                'credits' => (int) $credits->sum('credit_minor'),
                'count' => Refund::count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in($this->methodValues())],
            'reference' => ['nullable', 'string', 'max:150'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $refund = $this->finance->refund(Student::findOrFail($data['student_id']), [
            'amount_minor' => Money::fromMajor($data['amount']),
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'reason' => $data['reason'],
            'refund_date' => now()->toDateString(),
        ], $request->user());

        return back()->with('success', 'Refund '.$refund->refund_no.' processed.');
    }

    private function methods(): array
    {
        $labels = ['cash' => 'Cash', 'momo' => 'Mobile Money', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];

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
