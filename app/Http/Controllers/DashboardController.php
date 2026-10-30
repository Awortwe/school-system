<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\CalendarEvent;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ResultSheet;
use App\Models\Term;
use App\Support\StudentAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $term = Term::where('is_current', true)->with('academicYear')->first();
        $sections = StudentAccess::sections($user);
        $students = StudentAccess::students($user);
        $attendance = $user->can('attendance.view')
            ? AttendanceRecord::where('date', today()->toDateString())
                ->whereIn('class_section_id', (clone $sections)->select('id'))
                ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')
            : collect();
        $total = $attendance->sum();
        $pending = $user->can('results.view')
            ? ResultSheet::whereIn('class_section_id', (clone $sections)->select('id'))
                ->when(! StudentAccess::schoolWide($user), fn ($query) => $query->where('teacher_id', $user->id))
                ->when($term, fn ($query) => $query->where('term_id', $term->id))
                ->where('status', $user->can('results.approve') ? 'submitted' : 'draft')->count()
            : null;

        // Financial report permission governs the payload as well as the UI.
        $finance = null;
        if ($user->can('reports.financial')) {
            $payments = Payment::where('status', 'confirmed');
            $finance = [
                'collections' => (int) (clone $payments)->when($term, fn ($query) => $query->where('term_id', $term->id))->sum('amount_minor'),
                'outstanding' => (int) Invoice::whereIn('status', ['issued', 'partially_paid'])->sum('balance_minor'),
                'expenses_month' => (int) Expense::where('status', 'approved')
                    ->whereBetween('expense_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount_minor'),
                'unreconciled_entries' => \App\Models\CashbookEntry::whereNull('reconciled_at')->count(),
                'recentPayments' => (clone $payments)->with('student')->latest('payment_date')->latest('id')->limit(6)->get()
                    ->map(fn ($payment) => [
                        'id' => $payment->id, 'receipt_no' => $payment->receipt_no,
                        'student' => $payment->student?->fullName(), 'amount_minor' => $payment->amount_minor,
                        'method' => $payment->method, 'payment_date' => $payment->payment_date?->toDateString(),
                    ]),
            ];
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'students' => $user->can('students.view') ? (clone $students)->where('status', 'active')->count() : null,
                'classes' => $user->can('classes.view') ? (clone $sections)->count() : null,
                'attendance_rate' => $total ? round((($attendance['present'] ?? 0) + ($attendance['late'] ?? 0)) / $total * 100, 1) : null,
                'attendance_total' => $total,
                'pending_results' => $pending,
            ],
            'finance' => $finance,
            'businessStats' => [
                'low_stock' => $user->can('inventory.view') ? \Illuminate\Support\Facades\DB::table('stock_items')->whereColumn('quantity', '<=', 'threshold')->count() : null,
                'pending_purchases' => $user->can('inventory.view') ? \Illuminate\Support\Facades\DB::table('purchase_requests')->where('status', 'requested')->count() : null,
                'transport_passengers' => $user->can('transport.view') ? \Illuminate\Support\Facades\DB::table('transport_enrollments')->where('start_date', '<=', today())->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', today()))->count() : null,
                'pending_payroll_runs' => $user->can('salaries.view') ? \Illuminate\Support\Facades\DB::table('payroll_periods')->whereIn('status', ['draft', 'review'])->count() : null,
            ],
            'administrativeTasks' => $user->can('admissions.view') ? ['applications' => \App\Models\Application::whereIn('status', ['submitted', 'under_review'])->count()] : null,
            'academicPerformance' => $user->can('results.approve') ? \App\Models\TermSubjectResult::where('is_complete', true)->whereHas('resultSheet', fn ($query) => $query->whereIn('status', ['approved', 'published'])->when($term, fn ($query) => $query->where('term_id', $term->id)))->avg('total_score') : null,
            'teachingAssignments' => $user->can('subjects.view') ? \App\Models\ClassSubject::with('subject')->where('teacher_id', $user->id)->when($term, fn ($query) => $query->where('academic_year_id', $term->academic_year_id)->where(fn ($query) => $query->whereNull('term_id')->orWhere('term_id', $term->id)))->get()->map(fn ($assignment) => ['id' => $assignment->id, 'subject' => $assignment->subject?->name, 'section_id' => $assignment->class_section_id]) : [],
            'todayLessons' => $user->can('timetable.view') ? \App\Models\TimetableEntry::with(['subject', 'period'])->where('teacher_id', $user->id)->where('day_of_week', now()->dayOfWeekIso)->when($term, fn ($query) => $query->where('academic_year_id', $term->academic_year_id)->where(fn ($query) => $query->whereNull('term_id')->orWhere('term_id', $term->id)))->get()->map(fn ($entry) => ['subject' => $entry->subject?->name, 'period' => $entry->period?->name, 'room' => $entry->room]) : [],
            'activities' => \App\Models\AuditLog::when(! $user->can('audit.view'), fn ($query) => $query->where('user_id', $user->id))->latest('id')->limit(5)->get(['id', 'description', 'created_at']),
            'recentStudents' => ! $user->can('students.view') ? [] : (clone $students)->with('currentSection.schoolClass')->latest('id')->limit(6)->get()
                ->map(fn ($student) => [
                    'id' => $student->id, 'name' => $student->fullName(), 'admission_no' => $student->admission_no,
                    'class' => $student->currentSection?->displayName(), 'status' => $student->status,
                ]),
            'assignedClasses' => $user->can('classes.view') ? (clone $sections)->with('schoolClass')->get()
                ->map(fn ($section) => ['id' => $section->id, 'name' => $section->displayName()]) : [],
            'upcomingEvents' => CalendarEvent::where('start_date', '>=', today()->toDateString())->orderBy('start_date')->limit(5)
                ->get(['id', 'title', 'start_date', 'end_date', 'type']),
            'announcements' => Announcement::whereNotNull('published_at')->where('published_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
                ->whereIn('audience', array_merge(['all_staff'], $user->getRoleNames()->all()))
                ->latest('published_at')->limit(4)->get(['id', 'title', 'body', 'published_at']),
        ]);
    }
}
