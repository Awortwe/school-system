<?php
namespace App\Http\Controllers;
use App\Support\{Audit, TabularExport};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class BusinessReportController extends Controller
{
    public function export(Request $r, string $module, string $type)
    {
        $r->validate(['format' => ['nullable', 'in:csv,xlsx,pdf']]);
        abort_unless(in_array($module, ['payroll', 'transport', 'inventory'], true) && $r->user()->can($module.'.view'), 403);
        if ($module === 'payroll') abort_unless($r->user()->can('salaries.view'), 403);
        $data = match ($module.'/'.$type) {
            'payroll/departments', 'payroll/deductions', 'payroll/outstanding' => $this->payrollReport($type),
            'payroll/entries' => DB::table('payroll_entries')->join('users', 'users.id', '=', 'user_id')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_period_id')->leftJoin('departments', 'departments.id', '=', 'users.department_id')->select('users.name', 'departments.name as department', 'payroll_periods.name as period', 'gross_minor', 'deductions_minor', 'net_minor', 'employer_minor', 'payroll_periods.status')->get(),
            'payroll/payments' => DB::table('salary_payments')->select('payroll_entry_id', 'kind', 'payment_date', 'amount_minor', 'method', 'reference')->get(),
            'transport/passengers' => DB::table('transport_enrollments')->join('students', 'students.id', '=', 'student_id')->join('transport_routes', 'transport_routes.id', '=', 'route_id')->select('first_name', 'last_name', 'admission_no', 'transport_routes.name as route', 'pickup', 'start_date', 'end_date')->get(),
            'transport/vehicles' => DB::table('transport_vehicles')->select('registration', 'type', 'capacity', 'status', 'insurance_expires', 'roadworthy_expires')->get(),
            'transport/drivers' => DB::table('transport_drivers')->select('name', 'phone', 'licence_number', 'licence_expires')->get(),
            'transport/spending' => DB::table('vehicle_maintenance')->select('vehicle_id', 'date', 'type', 'notes', 'cost_minor', 'expense_id')->get(),
            'transport/collections' => DB::table('transport_billings')->join('invoices', 'invoices.id', '=', 'invoice_id')->join('transport_enrollments', 'transport_enrollments.id', '=', 'transport_enrollment_id')->join('transport_routes', 'transport_routes.id', '=', 'route_id')->select('transport_routes.name as route', 'invoice_no', 'transport_billings.amount_minor', 'invoices.subtotal_minor', 'invoices.total_minor', 'invoices.paid_minor', 'invoices.status')->get()->map(function ($row) {
                $net = $row->status === 'void' ? 0 : \App\Support\Money::proportional($row->total_minor, $row->amount_minor, $row->subtotal_minor);
                $collected = min($net, \App\Support\Money::proportional($row->paid_minor, $row->amount_minor, $row->subtotal_minor));
                return (object) ['route' => $row->route, 'invoice' => $row->invoice_no, 'gross_transport_charge_minor' => $row->amount_minor, 'net_transport_charge_minor' => $net, 'allocated_collection_share_minor' => $collected, 'allocated_outstanding_share_minor' => $net - $collected, 'basis' => 'Proportional share of core invoice after adjustments'];
            }),
            'inventory/stock' => DB::table('stock_items')->select('code', 'name', 'unit', 'quantity', 'threshold', 'location_id')->get(),
            'inventory/low-stock' => DB::table('stock_items')->whereColumn('quantity', '<=', 'threshold')->select('code', 'name', 'unit', 'quantity', 'threshold', 'location_id')->get(),
            'inventory/movements' => DB::table('stock_movements')->select('stock_item_id', 'type', 'quantity_delta', 'balance', 'reason', 'user_id', 'created_at')->get(),
            'inventory/purchases' => DB::table('purchase_requests')->select('order_no', 'stock_item_id', 'supplier_id', 'quantity', 'total_minor', 'status', 'expense_id')->get(),
            'inventory/assets' => DB::table('fixed_assets')->select('code', 'description', 'purchase_date', 'purchase_minor', 'location_id', 'condition', 'assigned_to', 'department_id', 'status')->get(),
            'inventory/asset-events' => DB::table('asset_events')->select('fixed_asset_id', 'type', 'location_id', 'assigned_to', 'condition', 'reason', 'cost_minor', 'expense_id', 'user_id', 'created_at')->get(),
            default => abort(404),
        };
        Audit::record('business_report_exported', null, [], ['module' => $module, 'type' => $type, 'rows' => $data->count()], null, 'Restricted operational report exported');
        return TabularExport::response($data->map(fn ($row) => (array) $row)->all(), ucfirst($module).' · '.$type.' operational report', $r->input('format', 'csv'));
    }

    private function payrollReport(string $type)
    {
        $rows = DB::table('payroll_entries')->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_period_id')->select('payroll_entries.*', 'payroll_periods.name as period')->get()->map(function ($entry) {
            $snapshot = json_decode($entry->snapshot, true);
            $paid = (int) DB::table('salary_payments')->where('payroll_entry_id', $entry->id)->where('kind', 'salary')->sum('amount_minor');
            $net = $entry->net_minor + (int) DB::table('payroll_adjustments')->where('payroll_entry_id', $entry->id)->sum('net_delta_minor');
            return ['staff' => $snapshot['staff_name'], 'department' => $snapshot['department'] ?: 'Unassigned', 'period' => $entry->period, 'gross_minor' => $entry->gross_minor, 'deductions_minor' => $entry->deductions_minor, 'employee_pension_minor' => $snapshot['employee_pension_minor'], 'paye_minor' => $snapshot['paye_minor'], 'other_deductions_minor' => $entry->deductions_minor - $snapshot['employee_pension_minor'] - $snapshot['paye_minor'], 'net_minor' => $net, 'employer_minor' => $entry->employer_minor, 'paid_minor' => $paid, 'outstanding_minor' => $net - $paid];
        });
        if ($type === 'outstanding') return $rows->where('outstanding_minor', '>', 0)->values();
        if ($type === 'deductions') return $rows->map(fn ($row) => \Illuminate\Support\Arr::only($row, ['staff', 'department', 'period', 'employee_pension_minor', 'paye_minor', 'other_deductions_minor', 'deductions_minor']));
        return $rows->groupBy(fn ($row) => $row['period'].'|'.$row['department'])->map(function ($group) {
            $result = ['period' => $group->first()['period'], 'department' => $group->first()['department']];
            foreach (['gross_minor', 'deductions_minor', 'net_minor', 'employer_minor', 'paid_minor', 'outstanding_minor'] as $key) $result[$key] = (int) $group->sum($key);
            return $result;
        })->values();
    }
}
