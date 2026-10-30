<?php
namespace App\Support;
use App\Models\{Expense, ExpenseCategory};
class PayrollPrivacy
{
    public static function expenses($query, $user)
    {
        if ($user->can('salaries.view')) return $query;
        return $query->where(fn ($query) => $query->whereNull('expense_category_id')->orWhereNotIn('expense_category_id', ExpenseCategory::where('name', 'Payroll payments')->select('id')));
    }
    public static function cashbook($query, $user)
    {
        if ($user->can('salaries.view')) return $query;
        $ids = Expense::whereIn('expense_category_id', ExpenseCategory::where('name', 'Payroll payments')->select('id'))->select('id');
        return $query->where(fn ($query) => $query->whereNull('source_type')->orWhere('source_type', '!=', Expense::class)->orWhereNotIn('source_id', $ids));
    }
}
