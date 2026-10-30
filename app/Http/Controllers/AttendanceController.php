<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\ClassSection;
use App\Models\Student;
use App\Models\Term;
use App\Support\Audit;
use App\Support\StudentAccess;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        $sections = StudentAccess::sections($request->user())->with('schoolClass.level')->get()
            ->sortBy(fn (ClassSection $section) => [
                $section->schoolClass?->level?->sort_order ?? 0,
                $section->schoolClass?->sort_order ?? 0,
                $section->name,
            ])->values();

        $section = $sections->firstWhere('id', (int) $request->input('section_id')) ?? $sections->first();
        $date = $request->input('date', now()->toDateString());
        $request->validate(['date' => ['nullable', 'date'], 'section_id' => ['nullable', 'integer']]);
        if ($request->filled('section_id')) abort_unless($sections->contains('id', (int) $request->input('section_id')), 403);
        $session = $request->input('session', 'morning');
        $term = Term::where('is_current', true)->first();

        $students = collect();
        $records = collect();
        $summary = [];

        if ($section && $term) {
            $students = Student::where('current_section_id', $section->id)
                ->where('status', 'active')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->map(fn (Student $student) => [
                    'id' => $student->id,
                    'name' => $student->fullName(),
                    'admission_no' => $student->admission_no,
                ]);

            $records = AttendanceRecord::where('class_section_id', $section->id)
                ->where('date', $date)
                ->where('session', $session)
                ->get()
                ->keyBy('student_id')
                ->map(fn (AttendanceRecord $record) => [
                    'status' => $record->status,
                    'reason' => $record->reason,
                ]);

            $summary = AttendanceRecord::where('class_section_id', $section->id)
                ->where('date', $date)
                ->where('session', $session)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all();
        }

        return Inertia::render('Attendance/Index', [
            'sections' => $sections->map(fn (ClassSection $section) => [
                'id' => $section->id,
                'name' => $section->displayName(),
            ]),
            'students' => $students,
            'records' => $records,
            'summary' => $summary,
            'filters' => [
                'section_id' => $section?->id,
                'date' => $date,
                'session' => $session,
            ],
            'statuses' => ['present', 'absent', 'late', 'excused'],
            'sessions' => Setting::get('attendance_sessions', ['morning']),
            'currentTerm' => $term?->only(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'class_section_id' => ['required', 'exists:class_sections,id'],
            'date' => ['required', 'date'],
            'session' => ['required', Rule::in(Setting::get('attendance_sessions', ['morning']))],
            'records' => ['required', 'array'],
            'records.*.student_id' => ['required', 'distinct', Rule::exists('students', 'id')->where('current_section_id', $request->input('class_section_id'))->where('status', 'active')],
            'records.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])],
            'records.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        $term = Term::where('is_current', true)->firstOrFail();
        abort_unless(StudentAccess::sections($request->user())->whereKey($data['class_section_id'])->exists(), 403);
        if ($data['date'] < $term->start_date->toDateString() || $data['date'] > $term->end_date->toDateString()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['date' => 'Attendance date must be within the current term.']);
        }

        DB::transaction(function () use ($request, $data, $term) {
            foreach ($data['records'] as $record) {
                AttendanceRecord::updateOrCreate(
                    [
                        'student_id' => $record['student_id'],
                        'date' => $data['date'],
                        'session' => $data['session'],
                    ],
                    [
                        'class_section_id' => $data['class_section_id'],
                        'term_id' => $term->id,
                        'status' => $record['status'],
                        'reason' => $record['reason'] ?? null,
                        'recorded_by' => $request->user()->id,
                    ],
                );
            }

            Audit::record('attendance_recorded', null, [], [
                'class_section_id' => $data['class_section_id'],
                'date' => $data['date'],
                'session' => $data['session'],
                'count' => count($data['records']),
            ], null, 'Attendance recorded');
        });

        return back()->with('success', 'Attendance saved for '.$data['date'].'.');
    }
}
