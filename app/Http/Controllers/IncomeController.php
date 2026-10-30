<?php

namespace App\Http\Controllers;

use App\Models\IncomeCategory;
use App\Models\OtherIncome;
use App\Models\Setting;
use App\Services\FinanceService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IncomeController extends Controller
{
    public function __construct(private readonly FinanceService $finance)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'category_id', 'from', 'to']);

        $incomes = OtherIncome::with(['category', 'recordedBy'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('income_no', 'like', "%{$search}%")
                        ->orWhere('source', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('income_category_id', $id))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('income_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('income_date', '<=', $date))
            ->latest('income_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (OtherIncome $income) => [
                'id' => $income->id,
                'income_no' => $income->income_no,
                'income_date' => $income->income_date?->toDateString(),
                'source' => $income->source,
                'category' => $income->category?->name,
                'amount_minor' => $income->amount_minor,
                'method' => $income->payment_method,
                'reference' => $income->transaction_reference,
                'description' => $income->description,
                'recorded_by' => $income->recordedBy?->name,
            ]);

        return Inertia::render('Finance/Income/Index', [
            'incomes' => $incomes,
            'filters' => $filters,
            'categories' => IncomeCategory::orderBy('name')->get(['id', 'name']),
            'methods' => $this->methods(),
            'stats' => [
                'month' => (int) OtherIncome::whereMonth('income_date', now()->month)->whereYear('income_date', now()->year)->sum('amount_minor'),
                'year' => (int) OtherIncome::whereYear('income_date', now()->year)->sum('amount_minor'),
                'count' => OtherIncome::whereYear('income_date', now()->year)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'income_category_id' => ['nullable', 'exists:income_categories,id'],
            'income_date' => ['required', 'date'],
            'source' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in($this->methodValues())],
            'transaction_reference' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->finance->recordOtherIncome([
            'income_category_id' => $data['income_category_id'] ?? null,
            'income_date' => $data['income_date'],
            'source' => $data['source'],
            'amount_minor' => Money::fromMajor($data['amount']),
            'payment_method' => $data['payment_method'],
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'description' => $data['description'] ?? null,
        ], $request->user());

        return back()->with('success', 'Other income recorded.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:income_categories,name'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        IncomeCategory::create($data);

        return back()->with('success', 'Income category created.');
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
