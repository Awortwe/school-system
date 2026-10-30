<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\AssessmentComponent;
use App\Models\AssessmentScore;
use App\Models\AttendanceRecord;
use App\Models\ClassSection;
use App\Models\ClassSubject;
use App\Models\ConductAssessment;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Enrollment;
use App\Models\ExpenseCategory;
use App\Models\FeeCategory;
use App\Models\FeeItem;
use App\Models\FeeStructure;
use App\Models\GradeBand;
use App\Models\GradingScale;
use App\Models\Guardian;
use App\Models\IncomeCategory;
use App\Models\Level;
use App\Models\ResultSheet;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\FinanceService;
use App\Services\GradingService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (AcademicYear::where('name', '2026/2027')->exists()) {
            return;
        }

        $finance = app(FinanceService::class);
        $grading = app(GradingService::class);

        $year = AcademicYear::create([
            'name' => '2026/2027',
            'start_date' => '2026-09-08',
            'end_date' => '2027-07-23',
            'is_current' => true,
        ]);

        $term1 = Term::create([
            'academic_year_id' => $year->id,
            'name' => 'Term 1',
            'sequence' => 1,
            'start_date' => '2026-09-08',
            'end_date' => '2026-12-18',
            'reopening_date' => '2027-01-12',
            'is_current' => true,
            'status' => 'open',
        ]);

        $term2 = Term::create([
            'academic_year_id' => $year->id,
            'name' => 'Term 2',
            'sequence' => 2,
            'start_date' => '2027-01-12',
            'end_date' => '2027-04-02',
            'reopening_date' => '2027-04-20',
            'is_current' => false,
            'status' => 'open',
        ]);

        $term3 = Term::create([
            'academic_year_id' => $year->id,
            'name' => 'Term 3',
            'sequence' => 3,
            'start_date' => '2027-04-20',
            'end_date' => '2027-07-23',
            'reopening_date' => '2027-09-07',
            'is_current' => false,
            'status' => 'open',
        ]);

        $terms = [$term1, $term2, $term3];

        $academicDepartment = Department::create(['name' => 'Academic']);
        $financeDepartment = Department::create(['name' => 'Finance']);
        $adminDepartment = Department::create(['name' => 'Administration']);

        $teacherDesignation = Designation::create(['name' => 'Teacher']);
        $accountantDesignation = Designation::create(['name' => 'Accountant']);
        $headteacherDesignation = Designation::create(['name' => 'Headteacher']);

        $teacher = $this->staff('Demo Teacher', 'teacher@demo.thesanctuary.test', $academicDepartment, $teacherDesignation, 'teacher');
        $accountant = $this->staff('Demo Accountant', 'accountant@demo.thesanctuary.test', $financeDepartment, $accountantDesignation, 'accountant');
        $headteacher = $this->staff('Demo Headteacher', 'headteacher@demo.thesanctuary.test', $adminDepartment, $headteacherDesignation, 'headteacher');

        $levels = collect([
            ['name' => 'Preschool', 'code' => 'PRE', 'curriculum' => 'Montessori', 'campus' => 'montessori', 'sort_order' => 1],
            ['name' => 'Primary', 'code' => 'PRI', 'curriculum' => 'GES', 'campus' => 'international', 'sort_order' => 2],
            ['name' => 'Junior High School', 'code' => 'JHS', 'curriculum' => 'GES', 'campus' => 'international', 'sort_order' => 3],
            ['name' => 'Lower Secondary', 'code' => 'LSC', 'curriculum' => 'Cambridge', 'campus' => 'international', 'sort_order' => 4],
        ])->mapWithKeys(fn (array $data) => [$data['code'] => Level::create($data)]);

        $classNames = [
            'PRE' => ['Nursery', 'Kindergarten'],
            'PRI' => ['Primary 1', 'Primary 2', 'Primary 3', 'Primary 4', 'Primary 5', 'Primary 6'],
            'JHS' => ['JHS 1', 'JHS 2', 'JHS 3'],
            'LSC' => ['Lower Secondary 1', 'Lower Secondary 2'],
        ];

        $sections = [];

        foreach ($classNames as $levelCode => $names) {
            foreach ($names as $index => $name) {
                $class = SchoolClass::create([
                    'level_id' => $levels[$levelCode]->id,
                    'name' => $name,
                    'code' => $levelCode.'-'.($index + 1),
                    'sort_order' => $index + 1,
                ]);

                $sections[$name] = ClassSection::create([
                    'school_class_id' => $class->id,
                    'name' => 'A',
                    'room' => 'Room '.($index + 1),
                    'capacity' => 30,
                    'class_teacher_id' => $teacher->id,
                ]);
            }
        }

        $subjectsByLevel = [
            'PRE' => [
                ['Language and Literacy', 'PRE-LIT'],
                ['Numeracy', 'PRE-NUM'],
                ['Creative Arts', 'PRE-CRE'],
                ['Physical Development', 'PRE-PD'],
            ],
            'PRI' => [
                ['English Language', 'PRI-ENG'],
                ['Mathematics', 'PRI-MAT'],
                ['Science', 'PRI-SCI'],
                ['Social Studies', 'PRI-SOC'],
                ['Religious and Moral Education', 'PRI-RME'],
                ['Ghanaian Language', 'PRI-GHL'],
                ['Creative Arts', 'PRI-CRE'],
                ['Computing', 'PRI-ICT'],
            ],
            'JHS' => [
                ['English Language', 'JHS-ENG'],
                ['Mathematics', 'JHS-MAT'],
                ['Integrated Science', 'JHS-SCI'],
                ['Social Studies', 'JHS-SOC'],
                ['Religious and Moral Education', 'JHS-RME'],
                ['Ghanaian Language', 'JHS-GHL'],
                ['Computing', 'JHS-ICT'],
                ['Career Technology', 'JHS-CAR'],
            ],
            'LSC' => [
                ['English', 'LSC-ENG'],
                ['Mathematics', 'LSC-MAT'],
                ['Science', 'LSC-SCI'],
                ['Global Perspectives', 'LSC-GLP'],
                ['ICT', 'LSC-ICT'],
                ['French', 'LSC-FRE'],
            ],
        ];

        $subjects = [];

        foreach ($subjectsByLevel as $levelCode => $rows) {
            foreach ($rows as $index => [$name, $code]) {
                $subjects[$code] = Subject::create([
                    'name' => $name,
                    'code' => $code,
                    'level_id' => $levels[$levelCode]->id,
                    'is_core' => $index < 4,
                    'is_examinable' => true,
                    'sort_order' => $index + 1,
                ]);
            }
        }

        foreach ($sections as $section) {
            $level = $section->schoolClass->level;
            $levelCode = $level->code;

            foreach ($subjectsByLevel[$levelCode] as [$name, $code]) {
                ClassSubject::create([
                    'class_section_id' => $section->id,
                    'subject_id' => $subjects[$code]->id,
                    'teacher_id' => $teacher->id,
                    'academic_year_id' => $year->id,
                    'term_id' => null,
                ]);
            }
        }

        $this->gradingScales($levels);
        $this->feeSetup($levels, $terms);

        $roster = [
            'Nursery' => [
                ['Ama', 'Akosua', 'Mensah', 'female', '2022-03-14', 'day'],
                ['Kojo', null, 'Owusu', 'male', '2022-07-02', 'day'],
                ['Efua', 'Adjoa', 'Baidoo', 'female', '2022-01-21', 'day'],
            ],
            'Primary 1' => [
                ['Kwame', 'Kofi', 'Asante', 'male', '2019-05-11', 'day'],
                ['Adwoa', 'Akua', 'Frimpong', 'female', '2019-09-30', 'day'],
                ['Yaw', null, 'Boateng', 'male', '2019-02-17', 'day'],
            ],
            'Primary 6' => [
                ['Akosua', 'Ama', 'Darko', 'female', '2014-06-08', 'day'],
                ['Kofi', 'Kwabena', 'Amankwah', 'male', '2014-11-23', 'day'],
                ['Abena', 'Akosua', 'Sarpong', 'female', '2014-04-12', 'day'],
            ],
            'JHS 1' => [
                ['Nana', 'Kwame', 'Osei', 'male', '2013-01-19', 'boarding'],
                ['Esi', 'Adwoa', 'Appiah', 'female', '2013-08-05', 'day'],
                ['Kwesi', 'Kofi', 'Annan', 'male', '2013-10-27', 'boarding'],
            ],
            'Lower Secondary 1' => [
                ['Selina', 'Ama', 'Arthur', 'female', '2012-12-03', 'day'],
                ['Daniel', 'Kojo', 'Tetteh', 'male', '2012-06-15', 'boarding'],
                ['Maame', 'Efua', 'Adjei', 'female', '2012-09-09', 'day'],
            ],
        ];

        $students = collect();
        $index = 0;

        foreach ($roster as $sectionName => $rows) {
            $section = $sections[$sectionName];

            foreach ($rows as [$first, $middle, $last, $gender, $dob, $category]) {
                $index++;
                $student = Student::create([
                    'admission_no' => DocumentNumberService::nextAdmission((int) $year->start_date->format('Y')),
                    'first_name' => $first,
                    'middle_name' => $middle,
                    'last_name' => $last,
                    'date_of_birth' => $dob,
                    'gender' => $gender,
                    'category' => $category,
                    'status' => 'active',
                    'admission_date' => '2026-09-08',
                    'current_section_id' => $section->id,
                    'address' => 'Anaji, Takoradi',
                    'phone' => '+233 24 000 00'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                    'nationality' => 'Ghanaian',
                ]);

                Guardian::create([
                    'first_name' => 'Parent of',
                    'last_name' => $last,
                    'phone' => '+233 20 100 00'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                    'email' => 'parent'.$index.'@demo.thesanctuary.test',
                    'address' => 'Anaji, Takoradi',
                    'occupation' => 'Demo occupation',
                ])->students()->attach($student->id, [
                    'relationship' => 'parent',
                    'is_primary' => true,
                    'is_emergency' => true,
                ]);

                Enrollment::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'term_id' => $term1->id,
                    'class_section_id' => $section->id,
                    'status' => 'active',
                    'enrolled_on' => '2026-09-08',
                ]);

                $students->push($student);
            }
        }

        $this->attendance($students, $term1, $teacher);
        $this->results($sections, $year, $term1, $grading, $teacher);
        $this->conduct($students, $term1);

        foreach ($students as $position => $student) {
            $invoice = $finance->invoiceForStudent($student, $term1, $student->category, $accountant);

            if ($invoice->total_minor <= 0) {
                continue;
            }

            $mod = $position % 4;
            $amount = match ($mod) {
                0 => $invoice->total_minor,
                1 => max(10000, intdiv($invoice->total_minor, 2)),
                2 => min($invoice->total_minor, 30000),
                default => 0,
            };

            if ($amount <= 0) {
                continue;
            }

            $finance->recordPayment($student, [
                'term_id' => $term1->id,
                'academic_year_id' => $year->id,
                'payment_date' => '2026-10-28',
                'amount_minor' => $amount,
                'method' => ['cash', 'momo', 'bank_transfer'][$mod],
                'transaction_reference' => $mod === 2 ? 'DEMO-BANK-'.$student->id : null,
                'notes' => 'Sample payment generated by DemoDataSeeder',
                'idempotency_key' => 'demo-payment-'.$student->id,
            ], $accountant);
        }

        $utilities = ExpenseCategory::create(['name' => 'Utilities']);
        $supplies = ExpenseCategory::create(['name' => 'Office Supplies']);
        $donations = IncomeCategory::create(['name' => 'Donations']);

        $finance->recordExpense([
            'expense_category_id' => $utilities->id,
            'expense_date' => '2026-10-20',
            'payee' => 'Ghana Water Company',
            'amount_minor' => 45000,
            'payment_method' => 'bank_transfer',
            'description' => 'Sample water bill for October 2026',
        ], $accountant);

        $approvedExpense = $finance->recordExpense([
            'expense_category_id' => $supplies->id,
            'expense_date' => '2026-10-22',
            'payee' => 'Demo Stationery Supplier',
            'amount_minor' => 120000,
            'payment_method' => 'cash',
            'description' => 'Sample stationery purchase',
        ], $accountant);

        $finance->approveExpense($approvedExpense, $headteacher);

        $finance->recordOtherIncome([
            'income_category_id' => $donations->id,
            'income_date' => '2026-10-24',
            'source' => 'Demo Alumni Donation',
            'amount_minor' => 200000,
            'payment_method' => 'bank_transfer',
            'description' => 'Sample donation for classroom resources',
        ], $accountant);

        Announcement::create([
            'title' => 'Sample: Welcome to the 2026/2027 academic year',
            'body' => 'This is sample data generated for demonstration. Replace or delete it before production use.',
            'audience' => 'all_staff',
            'published_at' => now(),
            'created_by' => $headteacher->id,
        ]);
    }

    private function staff(string $name, string $email, Department $department, Designation $designation, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('Password@123'),
            'employee_no' => 'TSS-DEMO-'.strtoupper(substr($role, 0, 3)),
            'phone' => '+233 50 000 0000',
            'department_id' => $department->id,
            'designation_id' => $designation->id,
            'employment_status' => 'active',
            'employment_date' => '2026-09-01',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function gradingScales($levels): void
    {
        $ges = GradingScale::create(['name' => 'GES 9-Point Scale', 'curriculum' => 'GES', 'is_default' => true]);

        $gesBands = [
            ['A1', 80, 100, 1, 'Excellent'],
            ['B2', 75, 79.99, 2, 'Very Good'],
            ['B3', 70, 74.99, 3, 'Good'],
            ['C4', 65, 69.99, 4, 'Credit'],
            ['C5', 60, 64.99, 5, 'Credit'],
            ['C6', 55, 59.99, 6, 'Credit'],
            ['D7', 50, 54.99, 7, 'Pass'],
            ['E8', 45, 49.99, 8, 'Weak Pass'],
            ['F9', 0, 44.99, 9, 'Fail'],
        ];

        foreach ($gesBands as $index => [$grade, $min, $max, $points, $remark]) {
            GradeBand::create([
                'grading_scale_id' => $ges->id,
                'grade' => $grade,
                'min_score' => $min,
                'max_score' => $max,
                'points' => $points,
                'remark' => $remark,
                'sort_order' => $index + 1,
            ]);
        }

        $cambridge = GradingScale::create(['name' => 'Cambridge A*-U Scale', 'curriculum' => 'Cambridge', 'is_default' => true]);

        $cambridgeBands = [
            ['A*', 90, 100, 'Outstanding'],
            ['A', 80, 89.99, 'Excellent'],
            ['B', 70, 79.99, 'Very Good'],
            ['C', 60, 69.99, 'Good'],
            ['D', 50, 59.99, 'Satisfactory'],
            ['E', 40, 49.99, 'Weak'],
            ['U', 0, 39.99, 'Ungraded'],
        ];

        foreach ($cambridgeBands as $index => [$grade, $min, $max, $remark]) {
            GradeBand::create([
                'grading_scale_id' => $cambridge->id,
                'grade' => $grade,
                'min_score' => $min,
                'max_score' => $max,
                'points' => null,
                'remark' => $remark,
                'sort_order' => $index + 1,
            ]);
        }

        $gesComponents = [
            ['Classwork', 'CLASSWORK', 10, 10],
            ['Homework', 'HOMEWORK', 10, 10],
            ['Project', 'PROJECT', 10, 10],
            ['Examination', 'EXAM', 70, 70],
        ];

        $cambridgeComponents = [
            ['Classwork', 'CLASSWORK', 15, 15],
            ['Homework', 'HOMEWORK', 10, 10],
            ['Project', 'PROJECT', 15, 15],
            ['Examination', 'EXAM', 60, 60],
        ];

        foreach ($levels as $level) {
            $components = $level->curriculum === 'Cambridge' ? $cambridgeComponents : $gesComponents;

            foreach ($components as $index => [$name, $code, $max, $weight]) {
                AssessmentComponent::create([
                    'level_id' => $level->id,
                    'curriculum' => $level->curriculum,
                    'name' => $name,
                    'code' => $code,
                    'max_score' => 100,
                    'weight' => $weight,
                    'is_exam' => $code === 'EXAM',
                    'sort_order' => $index + 1,
                ]);
            }
        }
    }

    private function feeSetup($levels, array $terms): void
    {
        $tuition = FeeCategory::create(['name' => 'Tuition']);
        $feeding = FeeCategory::create(['name' => 'Feeding']);
        $assessment = FeeCategory::create(['name' => 'Assessment']);
        $levies = FeeCategory::create(['name' => 'Levies']);
        $boarding = FeeCategory::create(['name' => 'Boarding']);

        $items = [
            'TUITION' => FeeItem::create(['fee_category_id' => $tuition->id, 'name' => 'Tuition', 'code' => 'TUITION', 'is_tuition' => true]),
            'FEEDING' => FeeItem::create(['fee_category_id' => $feeding->id, 'name' => 'Feeding', 'code' => 'FEEDING']),
            'EXAMS' => FeeItem::create(['fee_category_id' => $assessment->id, 'name' => 'Examinations', 'code' => 'EXAMS']),
            'PTA' => FeeItem::create(['fee_category_id' => $levies->id, 'name' => 'PTA Dues', 'code' => 'PTA']),
            'MAINT' => FeeItem::create(['fee_category_id' => $levies->id, 'name' => 'Maintenance', 'code' => 'MAINT']),
            'BOARDING' => FeeItem::create(['fee_category_id' => $boarding->id, 'name' => 'Boarding', 'code' => 'BOARDING']),
        ];

        $baseFees = ['PRE' => 80000, 'PRI' => 120000, 'JHS' => 150000, 'LSC' => 180000];

        foreach ($terms as $term) {
            foreach ($levels as $level) {
                $base = $baseFees[$level->code];

                $dayItems = [
                    ['TUITION', intdiv($base * 75, 100)],
                    ['FEEDING', intdiv($base * 15, 100)],
                    ['EXAMS', intdiv($base * 5, 100)],
                    ['PTA', 5000],
                    ['MAINT', 5000],
                ];

                $this->structure($level, $term, 'day', $dayItems, $items);

                if ($level->sort_order >= 3) {
                    $boardingItems = array_merge($dayItems, [['BOARDING', 120000]]);
                    $this->structure($level, $term, 'boarding', $boardingItems, $items);
                }
            }
        }
    }

    private function structure(Level $level, Term $term, string $category, array $rows, array $items): void
    {
        $structure = FeeStructure::create([
            'academic_year_id' => $term->academic_year_id,
            'term_id' => $term->id,
            'level_id' => $level->id,
            'school_class_id' => null,
            'category' => $category,
            'name' => $level->name.' '.ucfirst($category).' - '.$term->name,
            'status' => 'active',
        ]);

        foreach ($rows as [$code, $amount]) {
            $structure->items()->create([
                'fee_item_id' => $items[$code]->id,
                'amount_minor' => $amount,
            ]);
        }
    }

    private function attendance($students, Term $term, User $teacher): void
    {
        $statuses = ['present', 'present', 'present', 'present', 'late', 'present', 'absent', 'present', 'present', 'excused'];

        foreach ($students as $student) {
            $date = $term->start_date->copy();
            $count = 0;

            while ($count < 10) {
                if ($date->isWeekday()) {
                    $status = $statuses[($student->id + $count) % count($statuses)];

                    AttendanceRecord::updateOrCreate([
                        'student_id' => $student->id,
                        'date' => $date->toDateString(),
                        'session' => 'morning',
                    ], [
                        'class_section_id' => $student->current_section_id,
                        'term_id' => $term->id,
                        'status' => $status,
                        'reason' => in_array($status, ['absent', 'excused'], true) ? 'Sample attendance reason' : null,
                        'recorded_by' => $teacher->id,
                    ]);

                    $count++;
                }

                $date->addDay();
            }
        }
    }

    private function results($sections, AcademicYear $year, Term $term, GradingService $grading, User $teacher): void
    {
        foreach ($sections as $section) {
            $classSubjects = ClassSubject::with('subject')
                ->where('class_section_id', $section->id)
                ->where('academic_year_id', $year->id)
                ->get();

            $students = Student::where('current_section_id', $section->id)->get();

            foreach ($classSubjects as $classSubject) {
                $sheet = ResultSheet::create([
                    'class_section_id' => $section->id,
                    'subject_id' => $classSubject->subject_id,
                    'term_id' => $term->id,
                    'academic_year_id' => $year->id,
                    'teacher_id' => $teacher->id,
                    'status' => 'draft',
                ]);

                $components = $grading->componentsFor($sheet);

                foreach ($students as $student) {
                    foreach ($components as $component) {
                        $missing = ($student->id % 5 === 0 && $component->code === 'PROJECT');
                        $score = $missing ? null : 50 + (($student->id * 7 + $classSubject->subject_id * 3 + $component->sort_order * 5) % 46);

                        AssessmentScore::create([
                            'result_sheet_id' => $sheet->id,
                            'student_id' => $student->id,
                            'assessment_component_id' => $component->id,
                            'score' => $score,
                            'is_exempt' => false,
                            'entered_by' => $teacher->id,
                        ]);
                    }

                    $result = $grading->calculate($sheet, $student);
                    $result->update(['teacher_comment' => 'Sample comment: keep working consistently.']);
                }
            }
        }
    }

    private function conduct($students, Term $term): void
    {
        foreach ($students as $student) {
            ConductAssessment::create([
                'student_id' => $student->id,
                'term_id' => $term->id,
                'conduct' => 'Good',
                'attitude' => 'Positive',
                'interest' => 'Active',
                'class_teacher_comment' => 'Sample class teacher comment. Demonstrates steady progress.',
                'headteacher_comment' => 'Sample headteacher comment. Keep up the effort.',
                'height' => 120 + ($student->id % 30),
                'weight' => 25 + ($student->id % 15),
            ]);
        }
    }
}
