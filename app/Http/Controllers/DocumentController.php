<?php
namespace App\Http\Controllers;

use App\Models\{Document, Student, Payment, Expense};
use App\Support\{Audit, StudentAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $documents = Document::with('uploader')->when(! StudentAccess::schoolWide($request->user()), fn ($query) => $query->where('documentable_type', Student::class)->whereIn('documentable_id', StudentAccess::students($request->user())->select('id')))->latest()->paginate(20);
        return Inertia::render('Documents/Index', ['documents' => $documents, 'students' => StudentAccess::students($request->user())->get()->map(fn ($student) => ['id' => $student->id, 'name' => $student->fullName()])]);
    }
    public function store(Request $request)
    {
        $data = $request->validate(['student_id' => ['required', 'exists:students,id'], 'title' => ['required', 'string', 'max:200'], 'category' => ['nullable', 'string', 'max:50'], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,docx,xlsx,csv,txt', 'max:10240']]);
        $student = Student::findOrFail($data['student_id']); StudentAccess::authorize($request->user(), $student);
        $file = $request->file('file');
        $document = Document::create(['documentable_type' => Student::class, 'documentable_id' => $student->id, 'title' => $data['title'], 'category' => $data['category'] ?? null, 'path' => $file->store('documents', 'local'), 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'is_private' => true, 'uploaded_by' => $request->user()->id]);
        Audit::record('document_uploaded', $document, [], ['student_id' => $student->id, 'title' => $data['title']], null, 'Private document uploaded');
        return back()->with('success', 'Private document uploaded.');
    }
    public function download(Request $request, Document $document)
    {
        if ($document->documentable_type === Student::class) StudentAccess::authorize($request->user(), Student::findOrFail($document->documentable_id));
        else abort_unless(StudentAccess::schoolWide($request->user()), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);
        Audit::record('document_downloaded', $document, [], [], null, 'Private document downloaded');
        return Storage::disk('local')->download($document->path, $document->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }
    public function uploadPhoto(Request $request, Student $student)
    {
        StudentAccess::authorize($request->user(), $student);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $path = $request->file('photo')->store('students', 'local');
        $student->update(['photo_path' => $path]);
        Audit::record('student_photo_updated', $student, [], [], null, 'Private student photo updated');
        return back()->with('success', 'Student photo updated.');
    }
    public function photo(Request $request, Student $student)
    {
        StudentAccess::authorize($request->user(), $student);
        abort_unless($student->photo_path, 404);
        $disk = Storage::disk('local')->exists($student->photo_path) ? 'local' : 'public';
        return response()->file(Storage::disk($disk)->path($student->photo_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
    public function paymentProof(Payment $payment)
    {
        abort_unless($payment->attachment_path, 404);
        return Storage::disk('local')->download($payment->attachment_path);
    }
    public function expenseProof(Expense $expense)
    {
        abort_unless(\App\Support\PayrollPrivacy::expenses(Expense::query(), request()->user())->whereKey($expense->id)->exists(), 403);
        abort_unless($expense->attachment_path, 404);
        return Storage::disk('local')->download($expense->attachment_path);
    }
}
