<?php
namespace Tests\Feature;

use App\Models\{AcademicYear, AssessmentComponent, ClassSection, ClassSubject, FeeItem, FeeStructure, GradingScale, Invoice, Level, Payment, PublishedReport, ResultSheet, SchoolClass, Student, Subject, Term, User};
use App\Services\FinanceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage, DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class CompleteWorkflowsTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private User $teacher;
    private Term $term;
    private ClassSection $section;
    private Student $student;
    private ResultSheet $sheet;
    private AssessmentComponent $sba;
    private AssessmentComponent $exam;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(); $this->admin->assignRole('super_admin');
        $this->teacher = User::factory()->create(); $this->teacher->assignRole('teacher');
        $year = AcademicYear::create(['name' => '2026/27 example', 'start_date' => '2026-09-01', 'end_date' => '2027-08-01', 'is_current' => true]);
        $this->term = Term::create(['academic_year_id' => $year->id, 'name' => 'Example term', 'sequence' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-12-31', 'is_current' => true]);
        $level = Level::create(['name' => 'Example primary', 'code' => 'EP', 'curriculum' => 'GES']);
        $class = SchoolClass::create(['level_id' => $level->id, 'name' => 'Primary 1']);
        $this->section = ClassSection::create(['school_class_id' => $class->id, 'name' => 'A', 'class_teacher_id' => $this->teacher->id]);
        $this->student = Student::create(['admission_no' => 'EX-001', 'first_name' => 'Ama', 'last_name' => 'Example', 'status' => 'active', 'category' => 'day', 'current_section_id' => $this->section->id]);
        $subject = Subject::create(['name' => 'Example subject', 'code' => 'MATH']);
        ClassSubject::create(['class_section_id' => $this->section->id, 'subject_id' => $subject->id, 'teacher_id' => $this->teacher->id, 'academic_year_id' => $year->id, 'term_id' => $this->term->id]);
        $this->sheet = ResultSheet::create(['class_section_id' => $this->section->id, 'subject_id' => $subject->id, 'teacher_id' => $this->teacher->id, 'academic_year_id' => $year->id, 'term_id' => $this->term->id, 'status' => 'draft']);
        $this->sba = AssessmentComponent::create(['level_id' => $level->id, 'curriculum' => 'GES', 'name' => 'SBA', 'code' => 'SBA', 'max_score' => 50, 'weight' => 50]);
        $this->exam = AssessmentComponent::create(['level_id' => $level->id, 'curriculum' => 'GES', 'name' => 'Exam', 'code' => 'EX', 'max_score' => 100, 'weight' => 50, 'is_exam' => true]);
        $scale = GradingScale::create(['name' => 'Example scale', 'curriculum' => 'GES', 'is_default' => true]);
        $scale->bands()->create(['min_score' => 0, 'max_score' => 100, 'grade' => 'P', 'remark' => 'Example grade']);
        $this->actingAs($this->admin);
    }
    private function scores(?int $sba = 40, ?int $exam = 60): array
    {
        return ['scores' => [['student_id' => $this->student->id, 'assessment_component_id' => $this->sba->id, 'score' => $sba, 'is_exempt' => false], ['student_id' => $this->student->id, 'assessment_component_id' => $this->exam->id, 'score' => $exam, 'is_exempt' => false]], 'comments' => []];
    }
    private function invoice(): Invoice
    {
        $item = FeeItem::create(['name' => 'Example tuition', 'code' => 'TU']);
        $structure = FeeStructure::create(['academic_year_id' => $this->term->academic_year_id, 'term_id' => $this->term->id, 'name' => 'Example fees', 'category' => 'day', 'status' => 'active']);
        $structure->items()->create(['fee_item_id' => $item->id, 'amount_minor' => 12000]);
        return app(FinanceService::class)->invoiceForStudent($this->student, $this->term, 'day', $this->admin);
    }
    private function pay(int $amount, ?string $key = null): Payment
    {
        return app(FinanceService::class)->recordPayment($this->student, ['amount_minor' => $amount, 'payment_date' => '2026-10-30', 'term_id' => $this->term->id, 'method' => 'cash', 'idempotency_key' => $key ?? (string) Str::uuid()], $this->admin);
    }
    public function test_every_management_screen_has_a_working_inertia_response(): void
    {
        foreach (['/staff', '/staff/create', '/staff/'.$this->teacher->id.'/edit', '/admissions', '/admissions/create', '/academics', '/academics/assessment', '/attendance', '/attendance/summary', '/timetable', '/results', '/results/'.$this->sheet->id, '/results/reports', '/progress', '/finance/fees', '/finance/invoices', '/finance/invoices/create', '/finance/payments', '/finance/payments/create', '/finance/expenses', '/finance/income', '/finance/adjustments', '/finance/refunds', '/finance/cashbook', '/finance/reports', '/students/'.$this->student->id.'/statement', '/settings', '/administration', '/documents', '/imports', '/report-jobs', '/audit'] as $url) {
            $this->get($url)->assertOk();
        }
    }
    public function test_attendance_validates_class_membership_date_and_permissions(): void
    {
        $payload = ['class_section_id' => $this->section->id, 'date' => '2026-10-30', 'session' => 'morning', 'records' => [['student_id' => $this->student->id, 'status' => 'late', 'reason' => 'Example']]];
        $this->actingAs($this->teacher)->post('/attendance', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_records', ['student_id' => $this->student->id, 'status' => 'late']);
        $payload['date'] = '2025-01-01'; $this->post('/attendance', $payload)->assertSessionHasErrors('date');
        $payload['date'] = '2026-10-30'; $payload['class_section_id'] = ClassSection::create(['school_class_id' => $this->section->school_class_id, 'name' => 'B'])->id;
        $this->post('/attendance', $payload)->assertForbidden();
    }
    public function test_scores_missing_maxima_calculations_approval_lock_reopen_and_publication(): void
    {
        Storage::fake('local');
        $this->actingAs($this->teacher)->put('/results/'.$this->sheet->id.'/scores', $this->scores(51))->assertSessionHasErrors('scores');
        $this->put('/results/'.$this->sheet->id.'/scores', $this->scores(40, null))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('term_subject_results', ['student_id' => $this->student->id, 'is_complete' => false, 'total_score' => null]);
        $this->post('/results/'.$this->sheet->id.'/submit')->assertSessionHasErrors('scores');
        $this->put('/results/'.$this->sheet->id.'/scores', $this->scores())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('term_subject_results', ['total_score' => 70, 'is_complete' => true]);
        $this->post('/results/'.$this->sheet->id.'/submit')->assertSessionHasNoErrors();
        $this->post('/results/'.$this->sheet->id.'/approve')->assertForbidden();
        $this->actingAs($this->admin)->post('/results/'.$this->sheet->id.'/approve')->assertSessionHasNoErrors();
        $this->assertTrue($this->sheet->fresh()->is_locked);
        $this->put('/results/'.$this->sheet->id.'/scores', $this->scores(0, 0))->assertSessionHas('error');
        $this->assertDatabaseHas('term_subject_results', ['total_score' => 70]);
        $this->post('/results/publish', ['class_section_id' => $this->section->id, 'term_id' => $this->term->id])->assertSessionHasNoErrors();
        $report = PublishedReport::firstOrFail();
        $payload = $report->payload;
        $this->get('/report-cards/published/'.$report->id)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/report-cards/classes/'.$this->section->id.'/'.$this->term->id)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->post('/results/'.$this->sheet->id.'/reopen', [])->assertSessionHasErrors('reason');
        $this->post('/results/'.$this->sheet->id.'/reopen', ['reason' => 'Correct entry'])->assertSessionHasNoErrors();
        $this->assertFalse($this->sheet->fresh()->is_locked);
        $this->assertSame($payload, $report->fresh()->payload);
        $this->assertDatabaseHas('result_events', ['event' => 'reopened']);
    }
    public function test_student_cannot_be_scored_by_another_teacher(): void
    {
        $teacher = User::factory()->create(); $teacher->assignRole('teacher');
        $this->actingAs($teacher)->get('/results/'.$this->sheet->id)->assertForbidden();
        $this->put('/results/'.$this->sheet->id.'/scores', $this->scores())->assertForbidden();
    }
    public function test_partial_payment_overpayment_refund_reversal_and_receipt_copy(): void
    {
        $invoice = $this->invoice(); $first = $this->pay(5000); $second = $this->pay(9000);
        $this->assertSame(0, $invoice->fresh()->balance_minor);
        $this->assertSame(2000, app(FinanceService::class)->creditBalance($this->student));
        $this->get('/finance/invoices/'.$invoice->id)->assertOk();
        $this->get('/finance/invoices/'.$invoice->id.'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/students/'.$this->student->id.'/statement/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/finance/payments/'.$first->id.'/receipt')->assertOk();
        $this->get('/finance/payments/'.$first->id.'/receipt')->assertOk();
        $this->assertDatabaseCount('receipt_reprints', 2);
        $this->assertSame('Ama  Example', $first->fresh()->receipt_snapshot['student_name']);
        app(FinanceService::class)->refund($this->student, ['amount_minor' => 2000, 'method' => 'cash', 'reason' => 'Credit refund'], $this->admin);
        $this->assertSame(0, app(FinanceService::class)->currentBalance($this->student));
        $this->post('/finance/payments/'.$second->id.'/reverse', ['reason' => 'Recorded in error'])->assertSessionHasNoErrors();
        $this->assertSame('reversed', $second->fresh()->status);
        $this->assertSame(7000, $invoice->fresh()->balance_minor);
        $this->assertDatabaseHas('cashbook_entries', ['type' => 'payment_reversal', 'outflow_minor' => 9000]);
        $this->post('/finance/payments/'.$second->id.'/reverse', ['reason' => 'Again'])->assertSessionHasErrors();
    }
    public function test_duplicate_payments_and_cross_student_allocations_are_rejected_atomically(): void
    {
        $invoice = $this->invoice(); $key = (string) Str::uuid(); $this->pay(1000, $key);
        $payload = ['student_id' => $this->student->id, 'amount' => '10.00', 'method' => 'cash', 'payment_date' => '2026-10-30', 'idempotency_key' => $key];
        $this->post('/finance/payments', $payload)->assertSessionHasErrors();
        $this->assertDatabaseCount('payments', 1);
        $other = Student::create(['admission_no' => 'EX-2', 'first_name' => 'Kofi', 'last_name' => 'Example']);
        $payload['student_id'] = $other->id; $payload['idempotency_key'] = (string) Str::uuid(); $payload['allocations'] = [$invoice->id => '10.00'];
        $this->post('/finance/payments', $payload)->assertSessionHasErrors('allocations');
        $this->assertDatabaseCount('payments', 1);
    }
    public function test_private_upload_is_not_accessible_to_teacher_without_document_permission(): void
    {
        Storage::fake('local');
        $this->post('/documents', ['student_id' => $this->student->id, 'title' => 'Example private record', 'file' => UploadedFile::fake()->create('record.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $document = \App\Models\Document::firstOrFail();
        Storage::disk('local')->assertExists($document->path);
        $this->get('/documents/'.$document->id.'/download')->assertOk();
        $this->actingAs($this->teacher)->get('/documents/'.$document->id.'/download')->assertForbidden();
    }
    public function test_admissions_require_approval_and_do_not_create_login_accounts(): void
    {
        $this->post('/admissions', ['academic_year_id' => $this->term->academic_year_id, 'first_name' => 'Kojo', 'last_name' => 'Example', 'guardian_name' => 'Example Guardian', 'guardian_phone' => '0200000000', 'guardian_relationship' => 'parent'])->assertSessionHasNoErrors();
        $application = \App\Models\Application::firstOrFail();
        $placement = ['class_section_id' => $this->section->id, 'category' => 'day'];
        $this->post('/admissions/'.$application->id.'/convert', $placement)->assertStatus(422);
        $this->post('/admissions/'.$application->id.'/decide', ['status' => 'approved'])->assertSessionHasNoErrors();
        $this->post('/admissions/'.$application->id.'/convert', $placement)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('students', 2);
        $this->post('/admissions/'.$application->id.'/convert', $placement)->assertRedirect();
        $this->assertDatabaseCount('students', 2);
    }
    public function test_staff_cannot_escalate_to_super_administrator_or_edit_one(): void
    {
        $head = User::factory()->create(); $head->assignRole('headteacher');
        $this->actingAs($head)->post('/staff', ['role' => 'super_admin'])->assertForbidden();
        $this->put('/staff/'.$this->admin->id, [])->assertForbidden();
        $this->post('/staff/'.$this->admin->id.'/toggle')->assertForbidden();
        $this->actingAs($this->admin)->put('/staff/'.$this->admin->id, ['role' => 'teacher', 'is_active' => false])->assertStatus(422);
    }
    public function test_import_validates_rows_without_creating_accounts(): void
    {
        Storage::fake('local');
        $csv = "admission_no,first_name,last_name,date_of_birth,gender,phone,guardian_name,guardian_phone\nIMP-1,Ama,Imported,2018-01-01,female,0200000000,,\nIMP-2,,Invalid,,,,,\n";
        $this->post('/imports', ['file' => UploadedFile::fake()->createWithContent('students.csv', $csv), 'class_section_id' => $this->section->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('import_batches', ['status' => 'completed', 'success_rows' => 1, 'error_rows' => 1]);
        $this->assertDatabaseHas('students', ['admission_no' => 'IMP-1']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_pdf_layout_samples_and_thermal_copy_and_reversal_markers(): void
    {
        $directory = base_path('tmp/pdfs'); if (! is_dir($directory)) mkdir($directory, 0700, true);
        $invoice = $this->invoice(); $payment = $this->pay(5000);
        foreach (['invoice' => '/finance/invoices/'.$invoice->id.'/pdf', 'statement' => '/students/'.$this->student->id.'/statement/pdf', 'receipt' => '/finance/payments/'.$payment->id.'/receipt'] as $name => $url) {
            $response = $this->get($url)->assertOk(); $bytes = $response->getContent(); $this->assertStringStartsWith('%PDF-', $bytes); file_put_contents($directory.'/'.$name.'.pdf', $bytes);
        }
        foreach ([58, 80] as $width) $this->get('/finance/payments/'.$payment->id.'/receipt?format=thermal&width='.$width)->assertOk()->assertSee('COPY')->assertSee($width.'mm', false);
        $this->post('/finance/payments/'.$payment->id.'/reverse', ['reason' => 'Example reversed receipt'])->assertSessionHasNoErrors();
        $this->get('/finance/payments/'.$payment->id.'/receipt?format=thermal&width=58')->assertOk()->assertSee('REVERSED')->assertSee('COPY');
        $this->put('/results/'.$this->sheet->id.'/scores', $this->scores())->assertSessionHasNoErrors();
        $payload = app(\App\Services\ReportCardService::class)->buildPayload($this->student, $this->term, $this->section, true);
        $payload['student']['name'] = 'Example learner with a deliberately long compound family name for layout verification';
        $subject = $payload['subjects'][0]; $payload['subjects'] = array_fill(0, 18, $subject);
        $service = app(\App\Services\ReportCardService::class);
        file_put_contents($directory.'/report-card.pdf', $service->render($payload)->output());
        $second = $payload; $second['student']['name'] = 'SECOND STUDENT - NEW PAGE CHECK';
        $batch = collect([new PublishedReport(['payload' => $payload]), new PublishedReport(['payload' => $second])]);
        $bytes = $service->renderBatch($batch)->output(); $this->assertStringStartsWith('%PDF-', $bytes); file_put_contents($directory.'/class-reports.pdf', $bytes);
    }

    public function test_prepaid_credit_is_applied_to_a_new_invoice_without_duplicate_ledger_credit(): void
    {
        $payment = $this->pay(5000); $original = $payment->receipt_snapshot;
        $invoice = $this->invoice();
        $this->assertSame(7000, $invoice->fresh()->balance_minor);
        $this->assertSame(0, $payment->fresh()->unallocated_minor);
        $this->assertSame(7000, app(FinanceService::class)->currentBalance($this->student));
        $this->assertSame($original, $payment->fresh()->receipt_snapshot);
        $this->assertDatabaseCount('student_ledger_entries', 2);
    }
    public function test_grading_configuration_is_frozen_for_an_existing_scored_sheet(): void
    {
        $this->put('/results/'.$this->sheet->id.'/scores', $this->scores())->assertSessionHasNoErrors()->assertRedirect();
        $this->sba->update(['weight' => 90]); $this->exam->update(['weight' => 10]);
        $this->put('/results/'.$this->sheet->id.'/scores', $this->scores())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('term_subject_results', ['total_score' => 70]);
    }
    public function test_timetable_teacher_and_class_overlaps_are_rejected(): void
    {
        $period = \App\Models\TimetablePeriod::create(['name' => 'Test period', 'start_time' => '09:00', 'end_time' => '10:00', 'sort_order' => 1]);
        $entry = ['day_of_week' => 1, 'timetable_period_id' => $period->id, 'subject_id' => $this->sheet->subject_id, 'teacher_id' => $this->teacher->id];
        $payload = ['class_section_id' => $this->section->id, 'term_id' => $this->term->id, 'entries' => [$entry]];
        $this->post('/timetable', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $other = ClassSection::create(['school_class_id' => $this->section->school_class_id, 'name' => 'B']); $payload['class_section_id'] = $other->id;
        $this->post('/timetable', $payload)->assertSessionHasErrors('entries');
        $payload['class_section_id'] = $this->section->id; $payload['entries'] = [$entry, $entry];
        $this->post('/timetable', $payload)->assertSessionHasErrors('entries');
        $this->assertDatabaseCount('timetable_entries', 1);
    }
    public function test_archived_student_remains_accessible_with_financial_history(): void
    {
        $invoice = $this->invoice();
        $this->delete('/students/'.$this->student->id, ['reason' => 'Historical record retained'])->assertRedirect('/students');
        $student = $this->student->fresh();
        $this->assertSame('archived', $student->status);
        $this->assertNull($student->deleted_at);
        $this->assertNotNull($student->archived_at);
        $this->get('/students/'.$student->id)->assertOk();
        $this->assertSame($student->id, $invoice->fresh()->student->id);
        $this->assertDatabaseHas('student_status_history', ['student_id' => $student->id, 'status' => 'archived', 'reason' => 'Historical record retained']);
        $this->assertSame($invoice->balance_minor, app(FinanceService::class)->currentBalance($student));
    }
    public function test_promotion_rule_requires_results_or_explicit_override_and_keeps_history(): void
    {
        $year = AcademicYear::create(['name' => '2027/28 example', 'start_date' => '2027-09-01', 'end_date' => '2028-08-01']);
        $rule = ['academic_year_id' => $this->term->academic_year_id, 'min_average' => 60, 'min_pass_subjects_percent' => 50, 'max_core_failures' => 0, 'subject_pass_mark' => 50];
        $this->post('/progress/rules', $rule)->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/progress/rules', array_replace($rule, ['min_average' => 65]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, \App\Models\PromotionRule::where('is_active', true)->count());
        $decision = ['student_id' => $this->student->id, 'term_id' => $this->term->id, 'target_year_id' => $year->id, 'to_section_id' => $this->section->id, 'decision' => 'promoted', 'notes' => 'Academic approver reviewed observation evidence'];
        $this->post('/progress/promotions', $decision)->assertStatus(422);
        $this->assertDatabaseCount('promotion_records', 0);
        $this->post('/progress/promotions', $decision + ['override_rule' => true])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('promotion_records', ['student_id' => $this->student->id, 'decision' => 'promoted']);
        $this->assertDatabaseHas('enrollments', ['student_id' => $this->student->id, 'academic_year_id' => $year->id, 'status' => 'active']);
        $this->post('/progress/promotions', $decision + ['override_rule' => true])->assertStatus(422);
        $this->actingAs($this->teacher)->post('/progress/rules', $rule)->assertForbidden();
    }
    public function test_expense_approval_reconciliation_and_exports(): void
    {
        $expense = app(FinanceService::class)->recordExpense(['expense_date' => '2026-10-30', 'payee' => 'Example supplier', 'description' => 'Example expense', 'amount_minor' => 12345, 'payment_method' => 'cash'], $this->admin);
        $this->post('/finance/expenses/'.$expense->id.'/approve')->assertSessionHasNoErrors()->assertRedirect();
        $entry = \App\Models\CashbookEntry::firstOrFail();
        $this->post('/finance/cashbook/'.$entry->id.'/reconcile')->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNotNull($entry->fresh()->reconciled_at);
        $this->get('/finance/export/cashbook')->assertOk();
        $this->get('/finance/export/cashbook?format=xlsx')->assertOk();
        $this->get('/finance/export/cashbook?format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
