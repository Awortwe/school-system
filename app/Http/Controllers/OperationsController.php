<?php
namespace App\Http\Controllers;

use App\Models\{Announcement, CalendarEvent, CommunicationLog, StaffAttendance, Student, Term, User};
use App\Support\{Audit, StudentAccess};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OperationsController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Administration/Index', [
            'events' => CalendarEvent::orderByDesc('start_date')->paginate(15, ['*'], 'events_page'),
            'announcements' => $request->user()->can('communication.send') ? Announcement::latest()->paginate(10, ['*'], 'announcements_page') : [],
            'attendance' => $request->user()->can('staff.update') ? StaffAttendance::with('user')->latest('date')->paginate(15, ['*'], 'staff_page') : [],
            'staff' => $request->user()->can('staff.update') ? User::where('is_active', true)->get(['id', 'name']) : [],
            'communications' => $request->user()->can('communication.view') ? CommunicationLog::with('student')->latest()->paginate(15, ['*'], 'communications_page') : [],
            'students' => $request->user()->can('communication.view') ? StudentAccess::students($request->user())->get()->map(fn ($student) => ['id' => $student->id, 'name' => $student->fullName()]) : [],
        ]);
    }
    public function event(Request $request)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'], 'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'type' => ['required', 'string', 'max:30'], 'location' => ['nullable', 'string', 'max:200']]);
        $event = CalendarEvent::create($data + ['created_by' => $request->user()->id]);
        Audit::record('calendar_event_created', $event, [], $data, null, 'School calendar event recorded');
        return back()->with('success', 'Calendar event created.');
    }
    public function announcement(Request $request)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:10000'], 'audience' => ['required', Rule::in(['all_staff', 'teacher', 'headteacher', 'accountant', 'administrator', 'super_admin'])], 'published_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date', 'after:published_at']]);
        Announcement::create($data + ['created_by' => $request->user()->id]);
        Audit::record('announcement_created', null, [], ['title' => $data['title']], null, 'Staff announcement created');
        return back()->with('success', 'Announcement saved. Blank publication date keeps it as a draft.');
    }
    public function staffAttendance(Request $request)
    {
        $data = $request->validate(['user_id' => ['required', 'exists:users,id'], 'date' => ['required', 'date'], 'status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])], 'notes' => ['nullable', 'string', 'max:1000']]);
        StaffAttendance::updateOrCreate(['user_id' => $data['user_id'], 'date' => $data['date']], $data + ['recorded_by' => $request->user()->id]);
        Audit::record('staff_attendance_recorded', null, [], $data, null, 'Staff attendance recorded');
        return back()->with('success', 'Staff attendance saved.');
    }
    public function communication(Request $request)
    {
        $data = $request->validate(['student_id' => ['required', 'exists:students,id'], 'channel' => ['required', Rule::in(['phone', 'email', 'meeting', 'letter', 'sms'])], 'direction' => ['required', Rule::in(['incoming', 'outgoing'])], 'subject' => ['required', 'string', 'max:200'], 'summary' => ['required', 'string', 'max:10000'], 'occurred_at' => ['required', 'date'], 'follow_up_at' => ['nullable', 'date']]);
        StudentAccess::authorize($request->user(), Student::findOrFail($data['student_id']));
        CommunicationLog::create($data + ['user_id' => $request->user()->id]);
        return back()->with('success', 'Communication record saved.');
    }
    public function attendanceSummary(Request $request)
    {
        $request->validate(['term_id' => ['nullable', 'exists:terms,id'], 'section_id' => ['nullable', 'exists:class_sections,id']]);
        $term = Term::find($request->input('term_id')) ?? Term::where('is_current', true)->first();
        $students = StudentAccess::students($request->user())->when($request->filled('section_id'), fn ($query) => $query->where('current_section_id', $request->input('section_id')))->with('currentSection.schoolClass')->paginate(30)->withQueryString()->through(fn ($student) => ['id' => $student->id, 'name' => $student->fullName(), 'class' => $student->currentSection?->displayName()] + ($term ? app(\App\Services\AttendanceSummaryService::class)->forStudentTerm($student, $term) : []));
        return Inertia::render('Attendance/Summary', ['students' => $students, 'terms' => Term::get(['id', 'name']), 'sections' => StudentAccess::sections($request->user())->with('schoolClass')->get()->map(fn ($section) => ['id' => $section->id, 'name' => $section->displayName()]), 'filters' => $request->only('term_id', 'section_id')]);
    }
}
