<?php
namespace App\Http\Controllers;
use App\Models\ImportBatch;
use App\Support\StudentAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
class ImportController extends Controller
{
    public function index(Request $request) { return Inertia::render('Imports/Index', ['batches' => ImportBatch::latest()->paginate(15), 'sections' => StudentAccess::sections($request->user())->with('schoolClass')->get()->map(fn ($section) => ['id' => $section->id, 'name' => $section->displayName()])]); }
    public function store(Request $request) {
        abort_unless($request->user()->can('students.create'), 403);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:20480'], 'class_section_id' => ['nullable', 'exists:class_sections,id']]);
        if (! empty($data['class_section_id'])) abort_unless(StudentAccess::sections($request->user())->whereKey($data['class_section_id'])->exists(), 403);
        $batch = ImportBatch::create(['type' => 'students', 'file_name' => $request->file('file')->getClientOriginalName(), 'path' => $request->file('file')->storeAs('imports', \Illuminate\Support\Str::uuid().'.'.($request->file('file')->getClientOriginalExtension() === 'xlsx' ? 'xlsx' : 'csv'), 'local'), 'class_section_id' => $data['class_section_id'] ?? null, 'created_by' => $request->user()->id, 'status' => 'pending']);
        \App\Jobs\ImportStudents::dispatch($batch->id);
        return back()->with('success', 'Import queued. Refresh this page to follow progress.');
    }
    public function template() { return response("admission_no,first_name,last_name,date_of_birth,gender,phone,guardian_name,guardian_phone\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="student-import-template.csv"']); }
}
