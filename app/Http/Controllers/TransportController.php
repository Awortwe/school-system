<?php
namespace App\Http\Controllers;
use App\Models\{User, Student, Term, FeeCategory, FeeItem, ExpenseCategory};
use App\Services\{FinanceService, TransportService};
use App\Support\{Audit, Money};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TransportController extends Controller
{
    private function save(string $table, array $data): int { return DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]); }
    public function index()
    {
        return Inertia::render('Transport/Index', ['vehicles' => DB::table('transport_vehicles')->get(), 'drivers' => DB::table('transport_drivers')->get(), 'routes' => DB::table('transport_routes')->get()->map(function ($row) { $row->stops = json_decode($row->stops, true); $row->days = json_decode($row->days, true); return $row; }), 'assignments' => DB::table('transport_assignments')->join('transport_routes', 'transport_routes.id', '=', 'route_id')->join('transport_vehicles', 'transport_vehicles.id', '=', 'vehicle_id')->join('transport_drivers', 'transport_drivers.id', '=', 'driver_id')->select('transport_assignments.*', 'transport_routes.name as route', 'registration', 'transport_drivers.name as driver')->get(),
            'enrollments' => DB::table('transport_enrollments')->join('students', 'students.id', '=', 'student_id')->join('transport_routes', 'transport_routes.id', '=', 'route_id')->select('transport_enrollments.*', 'first_name', 'last_name', 'transport_routes.name as route')->get(), 'fees' => DB::table('transport_fees')->get(), 'maintenance' => DB::table('vehicle_maintenance')->latest('id')->paginate(20),
            'students' => Student::where('status', 'active')->get()->map(fn ($student) => ['id' => $student->id, 'name' => $student->fullName()]), 'terms' => Term::get(['id', 'name']), 'staff' => User::get(['id', 'name']),
            'reminders' => DB::table('transport_vehicles')->where(fn ($query) => $query->where('insurance_expires', '<=', now()->addDays(30)->toDateString())->orWhere('roadworthy_expires', '<=', now()->addDays(30)->toDateString()))->get(),
            'billings' => DB::table('transport_billings')->join('invoices', 'invoices.id', '=', 'invoice_id')->select('transport_billings.*', 'invoice_no', 'invoices.balance_minor')->get()]);
    }
    public function vehicle(Request $r) {
        $data = $r->validate(['registration' => ['required', 'string', 'max:40', 'unique:transport_vehicles,registration'], 'type' => ['required', 'string', 'max:50'], 'capacity' => ['required', 'integer', 'between:1,100'], 'status' => ['required', 'in:active,maintenance,inactive'], 'insurance_expires' => ['required', 'date'], 'roadworthy_expires' => ['required', 'date']]);
        $this->save('transport_vehicles', $data); Audit::record('vehicle_registered', null, [], $data, null, 'Transport vehicle registered'); return back()->with('success', 'Vehicle registered.');
    }
    public function driver(Request $r) {
        $data = $r->validate(['user_id' => ['nullable', 'exists:users,id'], 'name' => ['required', 'string', 'max:150'], 'phone' => ['required', 'string', 'max:40'], 'licence_number' => ['required', 'string', 'max:100'], 'licence_expires' => ['required', 'date']]);
        $this->save('transport_drivers', $data); return back()->with('success', 'Driver registered.');
    }
    public function updateRegistry(Request $r, string $kind, int $record) {
        abort_unless(in_array($kind, ['vehicles', 'drivers'], true), 404); $table = $kind === 'vehicles' ? 'transport_vehicles' : 'transport_drivers';
        $old = DB::table($table)->find($record); abort_unless($old, 404);
        $rules = $kind === 'vehicles' ? ['registration' => ['required', 'string', 'max:40', \Illuminate\Validation\Rule::unique($table, 'registration')->ignore($record)], 'type' => ['required', 'string', 'max:50'], 'capacity' => ['required', 'integer', 'between:1,100'], 'status' => ['required', 'in:active,maintenance,inactive'], 'insurance_expires' => ['required', 'date'], 'roadworthy_expires' => ['required', 'date']] : ['name' => ['required', 'string', 'max:150'], 'phone' => ['required', 'string', 'max:40'], 'licence_number' => ['required', 'string', 'max:100'], 'licence_expires' => ['required', 'date']];
        $data = $r->validate($rules);
        if ($kind === 'vehicles') foreach (DB::table('transport_assignments')->where('vehicle_id', $record)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', today()))->get() as $assignment) {
            $passengers = DB::table('transport_enrollments')->where('route_id', $assignment->route_id)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', today()))->count(); abort_if($passengers > $data['capacity'], 422, 'Capacity cannot be reduced below assigned passengers.');
        }
        DB::table($table)->where('id', $record)->update($data + ['updated_at' => now()]); Audit::record('transport_registry_updated', null, (array) $old, $data, null, 'Vehicle or driver record updated with history'); return back()->with('success', 'Transport registry updated.');
    }
    public function route(Request $r) {
        $data = $r->validate(['name' => ['required', 'string', 'max:150'], 'stops' => ['required', 'string', 'max:3000'], 'days' => ['required', 'string', 'max:30'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i', 'after:start_time']]);
        $days = array_map('intval', array_filter(array_map('trim', explode(',', $data['days']))));
        abort_unless($days && ! array_diff($days, range(1, 7)), 422, 'Use weekday numbers 1–7 separated by commas.');
        $data['stops'] = json_encode(array_values(array_filter(array_map('trim', explode(',', $data['stops']))))); $data['days'] = json_encode(array_values(array_unique($days)));
        $this->save('transport_routes', $data); return back()->with('success', 'Route and pickup/drop-off schedule saved.');
    }
    public function assignment(Request $r) {
        $data = $r->validate(['route_id' => ['required', 'exists:transport_routes,id'], 'vehicle_id' => ['required', 'exists:transport_vehicles,id'], 'driver_id' => ['required', 'exists:transport_drivers,id'], 'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date']]);
        DB::transaction(function () use ($data) {
            $vehicle = DB::table('transport_vehicles')->where('id', $data['vehicle_id'])->lockForUpdate()->first(); $driver = DB::table('transport_drivers')->where('id', $data['driver_id'])->lockForUpdate()->first(); $route = DB::table('transport_routes')->find($data['route_id']);
            $end = $data['end_date'] ?? '9999-12-31';
            abort_unless($vehicle->status === 'active' && $vehicle->insurance_expires >= $data['start_date'] && $vehicle->roadworthy_expires >= $data['start_date'] && $driver->licence_expires >= $data['start_date'], 422, 'Vehicle or driver is unavailable or required documents have expired.');
            $prior = DB::table('transport_assignments')->where('start_date', '<=', $end)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $data['start_date']))->where(fn ($q) => $q->where('vehicle_id', $data['vehicle_id'])->orWhere('driver_id', $data['driver_id'])->orWhere('route_id', $data['route_id']))->get();
            foreach ($prior as $assignment) { $other = DB::table('transport_routes')->find($assignment->route_id); abort_if(array_intersect(json_decode($other->days, true), json_decode($route->days, true)) && $route->start_time < $other->end_time && $route->end_time > $other->start_time, 422, 'Vehicle, driver or route assignment clashes with an existing schedule.'); }
            $passengers = DB::table('transport_enrollments')->where('route_id', $data['route_id'])->where('start_date', '<=', $end)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $data['start_date']))->count();
            abort_if($passengers > $vehicle->capacity, 422, 'Vehicle capacity is below the assigned passenger count.');
            $this->save('transport_assignments', $data);
        }); Audit::record('transport_assignment_created', null, [], $data, null, 'Vehicle and driver assigned with clash and capacity checks'); return back()->with('success', 'Vehicle and driver assignment saved.');
    }
    public function enroll(Request $r) {
        $data = $r->validate(['student_id' => ['required', 'exists:students,id'], 'route_id' => ['required', 'exists:transport_routes,id'], 'pickup' => ['required', 'string', 'max:150'], 'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date']]);
        DB::transaction(function () use ($data) {
            DB::table('transport_routes')->where('id', $data['route_id'])->lockForUpdate()->first();
            $end = $data['end_date'] ?? '9999-12-31';
            abort_if(DB::table('transport_enrollments')->where('student_id', $data['student_id'])->where('route_id', $data['route_id'])->where('start_date', '<=', $end)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $data['start_date']))->exists(), 422, 'This student already has an overlapping enrolment on this route.');
            $assignment = DB::table('transport_assignments')->where('route_id', $data['route_id'])->where('start_date', '<=', $data['start_date'])->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $end))->first();
            abort_unless($assignment, 422, 'Assign a vehicle and driver covering the full requested date range first.');
            $vehicle = DB::table('transport_vehicles')->where('id', $assignment->vehicle_id)->lockForUpdate()->first();
            $driver = DB::table('transport_drivers')->find($assignment->driver_id);
            abort_unless($vehicle->status === 'active' && $vehicle->insurance_expires >= $data['start_date'] && $vehicle->roadworthy_expires >= $data['start_date'] && $driver->licence_expires >= $data['start_date'], 422, 'Required vehicle or driver documents are no longer valid for this assignment.');
            $count = DB::table('transport_enrollments')->where('route_id', $data['route_id'])->where('start_date', '<=', $end)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $data['start_date']))->count();
            abort_if($count >= $vehicle->capacity, 422, 'Route vehicle has reached capacity.');
            $route = DB::table('transport_routes')->find($data['route_id']); abort_unless(in_array($data['pickup'], json_decode($route->stops, true), true), 422, 'Pickup must be a configured route stop.');
            $this->save('transport_enrollments', $data);
        }); Audit::record('student_transport_enrolled', null, [], $data, null, 'Student transport enrolment recorded'); return back()->with('success', 'Transport enrolment saved.');
    }
    public function close(Request $r, int $enrollment) { $data = $r->validate(['end_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:1000']]); $row = DB::table('transport_enrollments')->find($enrollment); abort_unless($row && $data['end_date'] >= $row->start_date, 422); DB::table('transport_enrollments')->where('id', $enrollment)->update(['end_date' => $data['end_date'], 'updated_at' => now()]); Audit::record('transport_enrollment_closed', null, [], ['id' => $enrollment], $data['reason'], 'Transport assignment ended; prior billing retained'); return back()->with('success', 'Transport end date recorded.'); }
    public function fee(Request $r) { $data = $r->validate(['route_id' => ['required', 'exists:transport_routes,id'], 'term_id' => ['required', 'exists:terms,id'], 'category' => ['required', 'in:all,day,boarding'], 'amount' => ['required', 'numeric', 'gt:0']]); $amount = Money::fromMajor($data['amount']); unset($data['amount']); $this->save('transport_fees', $data + ['amount_minor' => $amount]); return back()->with('success', 'Draft route fee saved; fee approval is required before billing.'); }
    public function approveFee(Request $r, int $fee) { DB::transaction(function () use ($r, $fee) { $row = DB::table('transport_fees')->where('id', $fee)->lockForUpdate()->first(); abort_unless($row && $row->status === 'draft', 422); $category = FeeCategory::firstOrCreate(['name' => 'Transport']); $item = FeeItem::firstOrCreate(['code' => 'TRANSPORT'], ['name' => 'School transport', 'fee_category_id' => $category->id, 'is_active' => true]); DB::table('transport_fees')->where('id', $fee)->update(['status' => 'approved', 'approved_by' => $r->user()->id, 'fee_item_id' => $item->id, 'updated_at' => now()]); Audit::record('transport_fee_approved', null, [], ['fee_id' => $fee], null, 'Route fee approved for core student billing'); }); return back()->with('success', 'Transport fee approved.'); }
    public function bill(Request $r, TransportService $service) { $data = $r->validate(['student_id' => ['required', 'exists:students,id'], 'term_id' => ['required', 'exists:terms,id']]); $service->bill(Student::findOrFail($data['student_id']), Term::findOrFail($data['term_id']), $r->user()); return back()->with('success', 'Transport charges added to the student invoice and account statement.'); }
    public function maintenance(Request $r, FinanceService $finance) { $data = $r->validate(['vehicle_id' => ['required', 'exists:transport_vehicles,id'], 'type' => ['required', 'in:service,fuel,repair,breakdown,inspection'], 'date' => ['required', 'date'], 'next_due' => ['nullable', 'date'], 'notes' => ['required', 'string', 'max:3000'], 'cost' => ['nullable', 'numeric', 'min:0']]); DB::transaction(function () use ($data, $r, $finance) { $cost = Money::fromMajor($data['cost'] ?? '0'); unset($data['cost']); $expense = $cost > 0 ? $finance->recordExpense(['expense_category_id' => ExpenseCategory::firstOrCreate(['name' => 'Transport operations'])->id, 'expense_date' => $data['date'], 'payee' => 'Vehicle #'.$data['vehicle_id'], 'description' => $data['type'].': '.$data['notes'], 'amount_minor' => $cost, 'payment_method' => 'bank_transfer'], $r->user()) : null; $this->save('vehicle_maintenance', $data + ['cost_minor' => $cost, 'expense_id' => $expense?->id]); if ($data['type'] === 'breakdown') DB::table('transport_vehicles')->where('id', $data['vehicle_id'])->update(['status' => 'maintenance']); Audit::record('vehicle_maintenance_recorded', null, [], ['vehicle_id' => $data['vehicle_id'], 'expense_id' => $expense?->id], null, 'Vehicle maintenance recorded with linked expense request'); }); return back()->with('success', 'Maintenance recorded; costs require normal expense approval.'); }
}

