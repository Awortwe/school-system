<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\ClassSection;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staff(string $role = 'teacher', array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['must_change_password' => false], $attributes));
        $user->assignRole($role);
        return $user;
    }

    private function section(?User $teacher = null): ClassSection
    {
        $level = Level::create(['name' => 'Example level', 'code' => uniqid('L')]);
        $class = SchoolClass::create(['level_id' => $level->id, 'name' => 'Example class']);
        return ClassSection::create(['school_class_id' => $class->id, 'name' => 'A', 'class_teacher_id' => $teacher?->id]);
    }

    private function student(ClassSection $section): Student
    {
        return Student::create(['admission_no' => uniqid('TEST-'), 'first_name' => 'Ama', 'last_name' => 'Example', 'current_section_id' => $section->id]);
    }

    public function test_guest_sees_the_real_login_component_and_protected_pages_redirect(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/students')->assertRedirect('/login');
    }

    public function test_staff_can_login_with_remember_me(): void
    {
        $user = $this->staff();
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_inactive_staff_cannot_login(): void
    {
        $user = $this->staff(attributes: ['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->staff();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_initial_password_must_be_changed(): void
    {
        $user = $this->staff(attributes: ['must_change_password' => true]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/change-password');
        $this->put('/change-password', ['current_password' => 'password', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertRedirect('/dashboard');
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }

    public function test_teacher_only_sees_assigned_students_and_no_financial_payload(): void
    {
        $teacher = $this->staff();
        $mine = $this->student($this->section($teacher));
        $other = $this->student($this->section());
        $this->actingAs($teacher)->get('/students')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Students/Index')->has('students.data', 1)->where('students.data.0.id', $mine->id)->where('students.data.0.balance_minor', null)->has('sections', 1));
        $this->get('/students/'.$other->id)->assertForbidden();
        $this->get('/students/'.$mine->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Students/Show')->where('balance_minor', null)->where('ledger', [])->where('invoices', [])->where('payments', []));
        $this->get('/students/create')->assertForbidden();
        $this->get('/finance/payments')->assertForbidden();
    }

    public function test_dashboard_is_scoped_and_does_not_publish_draft_announcements(): void
    {
        $teacher = $this->staff();
        $this->student($this->section($teacher));
        $this->student($this->section());
        Announcement::create(['title' => 'Draft', 'body' => 'Not published']);
        Announcement::create(['title' => 'Staff news', 'body' => 'Published', 'published_at' => now()]);
        $this->actingAs($teacher)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('finance', null)->where('stats.students', 1)->where('stats.classes', 1)
            ->has('recentStudents', 1)->has('announcements', 1)->where('announcements.0.title', 'Staff news'));
    }

    public function test_accountant_sees_financial_summary_but_cannot_create_students(): void
    {
        $user = $this->staff('accountant');
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('finance.collections', 0)->where('finance.outstanding', 0));
        $this->get('/students/create')->assertForbidden();
        $this->post('/students', [])->assertForbidden();
    }

    public function test_administrator_creates_student_guardian_enrolment_and_audit_without_accounts(): void
    {
        $user = $this->staff('administrator');
        $section = $this->section();
        AcademicYear::create(['name' => 'Example year', 'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'is_current' => true]);
        $this->actingAs($user)->post('/students', [
            'admission_no' => '', 'first_name' => 'Kofi', 'last_name' => 'Example', 'category' => 'day', 'status' => 'active',
            'current_section_id' => $section->id, 'guardian_name' => 'Akua', 'guardian_phone' => '0200000000',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $student = Student::firstOrFail();
        $this->assertDatabaseHas('enrollments', ['student_id' => $student->id, 'class_section_id' => $section->id]);
        $this->assertDatabaseHas('student_guardian', ['student_id' => $student->id, 'is_primary' => true]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'student_created']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_student_is_rejected_without_partial_records(): void
    {
        $this->actingAs($this->staff('administrator'))->post('/students', ['category' => 'invalid', 'status' => 'active'])
            ->assertSessionHasErrors(['first_name', 'last_name', 'category']);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('guardians', 0);
    }

    public function test_student_search_filters_and_pagination_are_database_backed(): void
    {
        $admin = $this->staff('administrator');
        $section = $this->section();
        for ($index = 0; $index < 17; $index++) {
            $this->student($section);
        }
        $this->actingAs($admin)->get('/students')->assertInertia(fn (Assert $page) => $page->has('students.data', 15)->where('students.total', 17));
        $this->get('/students?search=unmatched')->assertInertia(fn (Assert $page) => $page->has('students.data', 0));
    }

    public function test_subject_teacher_access_requires_a_current_assignment(): void
    {
        $teacher = $this->staff();
        $section = $this->section();
        $student = $this->student($section);
        $year = AcademicYear::create(['name' => 'Current example year', 'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'is_current' => true]);
        $term = Term::create(['academic_year_id' => $year->id, 'name' => 'Example term', 'sequence' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-12-15', 'is_current' => true]);
        $subject = Subject::create(['name' => 'Example subject', 'code' => 'EX']);
        $assignment = ClassSubject::create(['class_section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'academic_year_id' => $year->id, 'term_id' => $term->id]);
        $this->actingAs($teacher)->get('/students/'.$student->id)->assertOk();
        $term->update(['is_current' => false]);
        $this->get('/students/'.$student->id)->assertForbidden();
        $assignment->update(['term_id' => null]);
        $this->get('/students/'.$student->id)->assertOk();
        $year->update(['is_current' => false]);
        $this->get('/students/'.$student->id)->assertForbidden();
    }

    public function test_student_class_changes_append_enrolment_history(): void
    {
        $admin = $this->staff('administrator');
        $oldSection = $this->section();
        $newSection = $this->section();
        $student = $this->student($oldSection);
        $year = AcademicYear::create(['name' => 'Example year', 'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'is_current' => true]);
        $old = Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $year->id, 'class_section_id' => $oldSection->id, 'enrolled_on' => '2026-09-01']);
        $data = ['admission_no' => $student->admission_no, 'first_name' => 'Ama', 'last_name' => 'Example', 'category' => 'day', 'status' => 'active', 'current_section_id' => $newSection->id];
        $this->actingAs($admin)->get('/students/'.$student->id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Students/Edit'));
        $this->put('/students/'.$student->id, $data)->assertSessionHasNoErrors()->assertRedirect('/students/'.$student->id);
        $this->assertDatabaseCount('enrollments', 2);
        $this->assertDatabaseHas('enrollments', ['id' => $old->id, 'class_section_id' => $oldSection->id, 'status' => 'transferred']);
        $this->assertDatabaseHas('enrollments', ['student_id' => $student->id, 'class_section_id' => $newSection->id, 'status' => 'active']);
        $this->put('/students/'.$student->id, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_custom_teacher_permissions_do_not_bypass_record_scoping(): void
    {
        $teacher = $this->staff();
        $teacher->givePermissionTo(['students.update', 'students.delete']);
        $other = $this->student($this->section());
        $this->actingAs($teacher)->get('/students/'.$other->id.'/edit')->assertForbidden();
        $this->put('/students/'.$other->id, [])->assertForbidden();
        $this->delete('/students/'.$other->id)->assertForbidden();
        $this->assertDatabaseHas('students', ['id' => $other->id, 'deleted_at' => null]);
    }

    public function test_deactivated_existing_session_is_invalidated(): void
    {
        $user = $this->staff(attributes: ['is_active' => false]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }
}
