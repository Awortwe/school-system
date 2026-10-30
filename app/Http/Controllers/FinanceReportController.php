<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\OtherIncome;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Student;
use App\Models\StudentLedgerEntry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FinanceReportController extends Controller
{
    public function index(Request $request): Response
    {
        $from = Carbon::parse($request->get('from', now()->startOfYear()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->get('to', now()->toDateString()))->endOfDay();

        $fees = Payment::where('status', 'confirmed')->whereBetween('payment_date', [$from, $to]);
        $otherIncome = OtherIncome::whereBetween('income_date', [$from, $to]);
        $expenses = Expense::where('status', 'approved')->whereBetween('expense_date', [$from, $to]);
        $refunds = Refund::whereBetween('created_at', [$from, $to]);

        $feeTotal = (int) (clone $fees)->sum('amount_minor');
        $otherTotal = (int) (clone $otherIncome)->sum('amount_minor');
        $expenseTotal = (int) (clone $expenses)->sum('amount_minor');
        $refundTotal = (int) (clone $refunds)->sum('amount_minor');

        $expensesByCategory = (clone $expenses)
            ->leftJoin('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->select('expense_categories.name as category', DB::raw('SUM(expenses.amount_minor) as total'))
            ->groupBy('expense_categories.name')
            ->orderByDesc('total')
            ->get();

        $incomeByCategory = (clone $otherIncome)
            ->leftJoin('income_categories', 'other_incomes.income_category_id', '=', 'income_categories.id')
            ->select('income_categories.name as category', DB::raw('SUM(other_incomes.amount_minor) as total'))
            ->groupBy('income_categories.name')
            ->orderByDesc('total')
            ->get();

        $paymentsByMethod = (clone $fees)
            ->select('method', DB::raw('SUM(amount_minor) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('method')
            ->orderByDesc('total')
            ->get();

        $outstandingByClass = Invoice::whereIn('invoices.status', ['issued', 'partially_paid'])
            ->join('students', 'invoices.student_id', '=', 'students.id')
            ->leftJoin('class_sections', 'invoices.class_section_id', '=', 'class_sections.id')
            ->leftJoin('school_classes', 'class_sections.school_class_id', '=', 'school_classes.id')
            ->select('school_classes.name as class', DB::raw('SUM(invoices.balance_minor) as total'))
            ->groupBy('school_classes.name')
            ->orderByDesc('total')
            ->get();

        $topDebtors = Invoice::whereIn('invoices.status', ['issued', 'partially_paid'])
            ->join('students', 'invoices.student_id', '=', 'students.id')
            ->select('students.id', 'students.first_name', 'students.middle_name', 'students.last_name', 'students.admission_no', DB::raw('SUM(invoices.balance_minor) as total'))
            ->groupBy('students.id', 'students.first_name', 'students.middle_name', 'students.last_name', 'students.admission_no')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($student) => [
                'id' => $student->id,
                'name' => trim($student->first_name.' '.$student->middle_name.' '.$student->last_name),
                'admission_no' => $student->admission_no,
                'total' => (int) $student->total,
            ]);

        $monthly = collect(range(5, 0))->map(function (int $offset) {
            $month = Carbon::now()->subMonths($offset);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            return [
                'month' => $month->format('M Y'),
                'fees' => (int) Payment::where('status', 'confirmed')->whereBetween('payment_date', [$start, $end])->sum('amount_minor'),
                'expenses' => (int) Expense::where('status', 'approved')->whereBetween('expense_date', [$start, $end])->sum('amount_minor'),
            ];
        });

        $billed = (int) Invoice::whereBetween('issue_date', [$from, $to])->where('status', '!=', 'void')->sum('total_minor');
        $collected = (int) Invoice::whereBetween('issue_date', [$from, $to])->where('status', '!=', 'void')->sum('paid_minor');
        $outstanding = (int) Invoice::whereIn('invoices.status', ['issued', 'partially_paid'])->sum('balance_minor');
        $credits = StudentLedgerEntry::query()
            ->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')->from('student_ledger_entries')->groupBy('student_id');
            })
            ->where('balance_minor', '<', 0)
            ->sum('balance_minor');

        $categoryCollections = []; $classCollections = [];
        foreach (\App\Models\PaymentAllocation::with(['invoice.items.feeItem.feeCategory', 'payment'])->whereNull('reversed_at')->whereHas('payment', fn ($query) => $query->where('status', 'confirmed')->whereBetween('payment_date', [$from, $to]))->get() as $allocation) {
            $invoice = $allocation->invoice; $items = $invoice->items->values(); $remaining = (int) $allocation->amount_minor;
            foreach ($items as $index => $item) {
                $share = $index === $items->count() - 1 ? $remaining : min($remaining, \App\Support\Money::proportional($allocation->amount_minor, $item->net_minor, $invoice->subtotal_minor));
                $name = $item->feeItem?->feeCategory?->name ?? 'Uncategorised';
                $categoryCollections[$name] = ($categoryCollections[$name] ?? 0) + $share; $remaining -= $share;
            }
            $section = $invoice->class_section_id ? \App\Models\ClassSection::with('schoolClass')->find($invoice->class_section_id)?->displayName() : null;
            $label = $section ?? 'Historical class not recorded'; $classCollections[$label] = ($classCollections[$label] ?? 0) + $allocation->amount_minor;
        }
        return Inertia::render('Finance/Reports/Index', [
            'collectionsByCategory' => collect($categoryCollections)->map(fn ($amount, $name) => ['category' => $name, 'amount_minor' => $amount])->values(),
            'collectionsByClass' => collect($classCollections)->map(fn ($amount, $name) => ['class' => $name, 'amount_minor' => $amount])->values(),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => [
                'fees' => $feeTotal,
                'other_income' => $otherTotal,
                'income' => $feeTotal + $otherTotal,
                'expenses' => $expenseTotal,
                'refunds' => $refundTotal,
                'net' => $feeTotal + $otherTotal - $expenseTotal - $refundTotal,
                'billed' => $billed,
                'collected' => $collected,
                'outstanding' => $outstanding,
                'credits' => abs((int) $credits),
                'collection_rate' => $billed > 0 ? round(($collected / $billed) * 100, 1) : 0,
            ],
            'expensesByCategory' => $expensesByCategory,
            'incomeByCategory' => $incomeByCategory,
            'paymentsByMethod' => $paymentsByMethod,
            'outstandingByClass' => $outstandingByClass,
            'topDebtors' => $topDebtors,
            'monthly' => $monthly,
            'studentCount' => Student::where('status', 'active')->count(),
        ]);
    }
}

