<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Setting;
use App\Services\FinanceService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function __construct(private readonly FinanceService $finance)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'category_id', 'from', 'to']);

        $expenses = \App\Support\PayrollPrivacy::expenses(Expense::query(), $request->user())->with(['category', 'createdBy', 'approvedBy'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('expense_no', 'like', "%{$search}%")
                        ->orWhere('payee', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('expense_category_id', $id))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '<=', $date))
            ->latest('expense_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Expense $expense) => [
                'id' => $expense->id,
                'expense_no' => $expense->expense_no,
                'expense_date' => $expense->expense_date?->toDateString(),
                'payee' => $expense->payee,
                'category' => $expense->category?->name,
                'amount_minor' => $expense->amount_minor,
                'method' => $expense->payment_method,
                'description' => $expense->description,
                'status' => $expense->status,
                'created_by' => $expense->createdBy?->name,
                'approved_by' => $expense->approvedBy?->name,
                'rejection_reason' => $expense->rejection_reason,
            ]);

        return Inertia::render('Finance/Expenses/Index', [
            'expenses' => $expenses,
            'filters' => $filters,
            'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'methods' => $this->methods(),
            'stats' => [
                'pending' => (int) Expense::where('status', 'pending')->sum('amount_minor'),
                'approved' => (int) Expense::where('status', 'approved')->sum('amount_minor'),
                'month' => (int) Expense::where('status', 'approved')->whereMonth('expense_date', now()->month)->whereYear('expense_date', now()->year)->sum('amount_minor'),
                'count' => Expense::where('status', 'pending')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'expense_category_id' => ['nullable', 'exists:expense_categories,id'],
            'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'expense_date' => ['required', 'date'],
            'payee' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in($this->methodValues())],
            'transaction_reference' => ['nullable', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        $expense = $this->finance->recordExpense([
            'expense_category_id' => $data['expense_category_id'] ?? null,
            'expense_date' => $data['expense_date'],
            'payee' => $data['payee'],
            'amount_minor' => Money::fromMajor($data['amount']),
            'payment_method' => $data['payment_method'],
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'description' => $data['description'],
            'attachment_path' => $request->hasFile('proof') ? $request->file('proof')->store('expense-proofs', 'local') : null,
        ], $request->user());

        if ($request->user()->can('expenses.approve')) {
            $this->finance->approveExpense($expense, $request->user());

            return back()->with('success', 'Expense recorded and approved.');
        }

        return back()->with('success', 'Expense recorded and submitted for approval.');
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        abort_unless(\App\Support\PayrollPrivacy::expenses(Expense::query(), $request->user())->whereKey($expense->id)->exists(), 403);
        $this->finance->approveExpense($expense, $request->user());

        return back()->with('success', 'Expense approved and posted to the cashbook.');
    }

    public function reject(Request $request, Expense $expense): RedirectResponse
    {
        abort_unless(\App\Support\PayrollPrivacy::expenses(Expense::query(), $request->user())->whereKey($expense->id)->exists(), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->finance->rejectExpense($expense, $request->user(), $data['reason']);

        return back()->with('success', 'Expense rejected.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:expense_categories,name'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        ExpenseCategory::create($data);

        return back()->with('success', 'Expense category created.');
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
