<?php
namespace Tests\Feature;
use App\Models\{User, Student, AcademicYear, Term, FeeItem, FeeStructure, Expense};
use App\Services\{FinanceService, InventoryService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\TestCase;
class BusinessModulesTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    protected function setUp(): void { parent::setUp(); $this->withoutVite(); $this->seed(RolesAndPermissionsSeeder::class); $this->admin = User::factory()->create(); $this->admin->assignRole('super_admin'); $this->actingAs($this->admin); }
    private function student(string $code): Student { return Student::create(['admission_no' => $code, 'first_name' => 'Example', 'last_name' => $code, 'status' => 'active', 'category' => 'day']); }
    public function test_business_module_pages_and_salary_permissions(): void {
        foreach (['/payroll', '/transport', '/inventory', '/business-documents'] as $path) $this->get($path)->assertOk();
        $teacher = User::factory()->create(); $teacher->assignRole('teacher');
        foreach (['/payroll', '/transport', '/inventory', '/business-documents'] as $path) $this->actingAs($teacher)->get($path)->assertForbidden();
    }
    public function test_monthly_payroll_approval_partial_payment_lock_and_adjustments(): void {
        $staff = User::factory()->create(); $staff->assignRole('teacher');
        $this->post('/payroll/rules', ['name' => 'Synthetic test rates', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'source' => 'Synthetic test fixture, not statutory advice', 'employee_pension_rate' => '5.5', 'employer_pension_rate' => '13', 'nonresident_rate' => '25', 'pension_min' => '0', 'pension_max' => '100000', 'bands' => [['width' => '500', 'rate' => '0'], ['width' => null, 'rate' => '10']]])->assertSessionHasNoErrors()->assertRedirect();
        $rule = DB::table('payroll_rules')->first();
        $this->post('/payroll/profiles', ['user_id' => $staff->id, 'effective_from' => '2026-10-01', 'basic' => '1000', 'tax_status' => 'resident', 'pension_covered' => true, 'payment_details' => 'TEST BANK 1234', 'is_active' => true])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNotSame('TEST BANK 1234', DB::table('payroll_profiles')->value('payment_details'));
        $this->post('/payroll/periods', ['name' => 'Example October', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'payroll_rule_id' => $rule->id])->assertSessionHasNoErrors()->assertRedirect();
        $period = DB::table('payroll_periods')->first();
        $this->post('/payroll/'.$period->id.'/generate')->assertSessionHasNoErrors()->assertRedirect();
        $entry = DB::table('payroll_entries')->first();
        $this->assertSame(100000, $entry->gross_minor); $this->assertSame(9950, $entry->deductions_minor); $this->assertSame(90050, $entry->net_minor); $this->assertSame(13000, $entry->employer_minor);
        foreach (['entries', 'payments', 'departments', 'deductions', 'outstanding'] as $report) {
            $this->get('/business-reports/payroll/'.$report)->assertOk();
        }
        $this->get('/business-reports/payroll/departments?format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $payment = ['kind' => 'salary', 'amount' => '100', 'payment_date' => '2026-10-30', 'method' => 'bank_transfer', 'reference' => 'TEST-PAY-1', 'idempotency_key' => (string) Str::uuid()];
        $this->post('/payroll/entries/'.$entry->id.'/payments', $payment)->assertStatus(422);
        $this->post('/payroll/'.$period->id.'/stage', ['status' => 'review'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/payroll/'.$period->id.'/stage', ['status' => 'approved'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/payroll/'.$period->id.'/generate')->assertStatus(422);
        $this->post('/payroll/entries/'.$entry->id.'/payments', $payment)->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/payroll/entries/'.$entry->id.'/payments', $payment)->assertStatus(422);
        $this->assertDatabaseCount('salary_payments', 1); $this->assertDatabaseCount('expenses', 1); $this->assertDatabaseCount('cashbook_entries', 1);
        $head = User::factory()->create(); $head->assignRole('headteacher');
        $head->givePermissionTo(['expenses.view', 'reports.financial']);
        $this->actingAs($head)->get('/finance/expenses')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->has('expenses.data', 0));
        $this->get('/finance/cashbook')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->has('entries.data', 0));
        $this->get('/payroll')->assertForbidden();
        $this->get('/business-reports/payroll/entries')->assertForbidden();
        $this->actingAs($this->admin);
        $this->post('/payroll/entries/'.$entry->id.'/adjustments', ['amount' => '-900', 'reason' => 'Invalid reduction'])->assertStatus(422);
        $this->post('/payroll/entries/'.$entry->id.'/adjustments', ['amount' => '10', 'reason' => 'Approved correction'])->assertSessionHasNoErrors()->assertRedirect();
        $payslip = $this->get('/payroll/'.$period->id.'/payslips')->assertOk()->assertHeader('content-type', 'application/pdf');
        $directory = base_path('tmp/pdfs'); if (! is_dir($directory)) mkdir($directory, 0700, true); file_put_contents($directory.'/payslips.pdf', $payslip->getContent());
        $this->actingAs($staff)->get('/payroll/'.$period->id.'/payslips')->assertForbidden();
    }
    public function test_transport_capacity_conflicts_and_fees_use_existing_student_invoice(): void {
        $year = AcademicYear::create(['name' => 'Example year', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $term = Term::create(['academic_year_id' => $year->id, 'name' => 'Example term', 'sequence' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $this->post('/transport/vehicles', ['registration' => 'TEST-1', 'type' => 'Bus', 'capacity' => 1, 'status' => 'active', 'insurance_expires' => '2027-12-31', 'roadworthy_expires' => '2027-12-31'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/transport/drivers', ['name' => 'Test Driver', 'phone' => '0200000000', 'licence_number' => 'TEST', 'licence_expires' => '2027-12-31'])->assertSessionHasNoErrors()->assertRedirect();
        $route = ['name' => 'Test Route', 'stops' => 'Gate A, Gate B', 'days' => '1,2,3,4,5', 'start_time' => '07:00', 'end_time' => '08:00'];
        $this->post('/transport/routes', $route)->assertSessionHasNoErrors()->assertRedirect();
        $assignment = ['route_id' => 1, 'vehicle_id' => 1, 'driver_id' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-12-31'];
        $this->post('/transport/assignments', $assignment)->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/transport/routes', $route + [])->assertSessionHasNoErrors()->assertRedirect(); $assignment['route_id'] = 2;
        $this->post('/transport/assignments', $assignment)->assertStatus(422);
        $student = $this->student('EX-A'); $other = $this->student('EX-B');
        $enrollment = ['student_id' => $student->id, 'route_id' => 1, 'pickup' => 'Gate A', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31'];
        $this->post('/transport/enrollments', $enrollment)->assertSessionHasNoErrors()->assertRedirect();
        $enrollment['student_id'] = $other->id; $this->post('/transport/enrollments', $enrollment)->assertStatus(422);
        $item = FeeItem::create(['name' => 'Example tuition', 'code' => 'TU']); $structure = FeeStructure::create(['academic_year_id' => $year->id, 'term_id' => $term->id, 'category' => 'day', 'name' => 'Example fees', 'status' => 'active']); $structure->items()->create(['fee_item_id' => $item->id, 'amount_minor' => 10000]);
        $invoice = app(FinanceService::class)->invoiceForStudent($student, $term, 'day', $this->admin);
        $this->post('/transport/fees', ['route_id' => 1, 'term_id' => $term->id, 'category' => 'all', 'amount' => '25'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/transport/fees/1/approve')->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/transport/bill', ['student_id' => $student->id, 'term_id' => $term->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(12500, $invoice->fresh()->total_minor); $this->assertSame(12500, app(FinanceService::class)->currentBalance($student)); $this->assertDatabaseCount('invoices', 1);
        $this->post('/transport/bill', ['student_id' => $student->id, 'term_id' => $term->id])->assertStatus(422); $this->assertDatabaseCount('transport_billings', 1);
    }
    public function test_stock_purchasing_and_assets_keep_auditable_separate_histories(): void {
        $this->post('/inventory/categories', ['name' => 'Consumables', 'type' => 'consumable'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/inventory/categories', ['name' => 'Equipment', 'type' => 'asset'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/inventory/locations', ['name' => 'Main store'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/inventory/suppliers', ['name' => 'Example supplier'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/inventory/items', ['category_id' => 1, 'location_id' => 1, 'code' => 'PAPER', 'name' => 'Paper', 'unit' => 'ream', 'threshold' => '2'])->assertSessionHasNoErrors()->assertRedirect();
        foreach ([['opening', '5'], ['issue', '2'], ['return', '1']] as [$type, $quantity]) $this->post('/inventory/movements', ['stock_item_id' => 1, 'type' => $type, 'quantity' => $quantity, 'reason' => 'Example stock movement'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals('4.000', DB::table('stock_items')->value('quantity'));
        $this->post('/inventory/movements', ['stock_item_id' => 1, 'type' => 'issue', 'quantity' => '10', 'reason' => 'Invalid issue'])->assertStatus(422);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->post('/inventory/purchases', ['stock_item_id' => 1, 'supplier_id' => 1, 'quantity' => '3', 'total' => '60', 'reason' => 'Replenishment'])->assertSessionHasNoErrors()->assertRedirect();
        foreach (['approved', 'ordered', 'received'] as $status) $this->post('/inventory/purchases/1/stage', ['status' => $status])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals('7.000', DB::table('stock_items')->value('quantity')); $this->assertDatabaseCount('expenses', 1); $this->assertDatabaseCount('cashbook_entries', 0);
        $this->post('/inventory/purchases/1/stage', ['status' => 'received'])->assertStatus(422); $this->assertDatabaseCount('expenses', 1);
        $this->post('/inventory/assets', ['code' => 'ASSET-1', 'description' => 'Example computer', 'category_id' => 2, 'location_id' => 1, 'purchase' => '2000', 'purchase_date' => '2026-10-30', 'condition' => 'new'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/inventory/assets/1/events', ['type' => 'transfer', 'location_id' => 1, 'assigned_to' => $this->admin->id, 'reason' => 'Assigned to staff'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('fixed_assets', 1); $this->assertDatabaseCount('asset_events', 1); $this->assertEquals('7.000', DB::table('stock_items')->value('quantity'));
    }
}

