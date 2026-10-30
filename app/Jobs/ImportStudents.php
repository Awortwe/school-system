<?php
namespace App\Jobs;

use App\Models\{AcademicYear, Enrollment, Guardian, ImportBatch, Student, User};
use App\Services\DocumentNumberService;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Storage, Validator};

class ImportStudents implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 900;
    public function __construct(public int $batchId) {}
    public function handle(): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);
        if ($batch->status !== 'pending') return;
        $user = User::findOrFail($batch->created_by);
        abort_unless($user->is_active && $user->can('imports.manage') && $user->can('students.create'), 403);
        $batch->update(['status' => 'processing']);
        $path = Storage::disk('local')->path($batch->path);
        $reader = str_ends_with(strtolower($batch->path), '.xlsx') ? new \OpenSpout\Reader\XLSX\Reader() : new \OpenSpout\Reader\CSV\Reader();
        $errors = []; $success = 0; $total = 0;
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                foreach ($sheet->getRowIterator() as $index => $row) {
                    $values = $row->toArray();
                    if (! $headers) {
                        $headers = array_map(fn ($header) => strtolower(trim(ltrim((string) $header, "\xEF\xBB\xBF"))), $values);
                        if (count($headers) !== count(array_unique($headers)) || array_diff(['first_name', 'last_name'], $headers)) throw new \RuntimeException('Missing or duplicated import headers.');
                        continue;
                    }
                    if (count(array_filter($values, fn ($value) => $value !== '' && $value !== null)) === 0) continue;
                    $total++;
                    $values = array_pad(array_slice($values, 0, count($headers)), count($headers), null);
                    $data = array_combine($headers, $values);
                    $data += ['admission_no' => '', 'date_of_birth' => null, 'gender' => null, 'phone' => null, 'guardian_name' => null, 'guardian_phone' => null];
                    foreach ($data as $key => $value) if ($value instanceof \DateTimeInterface) $data[$key] = $value->format('Y-m-d');
                    $validator = Validator::make($data, ['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'admission_no' => ['nullable', 'string', 'max:50', 'unique:students,admission_no'], 'date_of_birth' => ['nullable', 'date', 'before:today'], 'gender' => ['nullable', 'in:male,female'], 'phone' => ['nullable', 'string', 'max:30'], 'guardian_name' => ['nullable', 'required_with:guardian_phone', 'string', 'max:100'], 'guardian_phone' => ['nullable', 'required_with:guardian_name', 'string', 'max:30']]);
                    if ($validator->fails()) { $errors[] = ['row' => $index, 'errors' => $validator->errors()->all()]; }
                    else {
                        try {
                            DB::transaction(function () use ($data, $batch, $user) {
                                $student = Student::create(['admission_no' => $data['admission_no'] ?: DocumentNumberService::nextAdmission(), 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'date_of_birth' => $data['date_of_birth'] ?: null, 'gender' => $data['gender'] ?: null, 'phone' => $data['phone'] ?: null, 'current_section_id' => $batch->class_section_id, 'category' => 'day', 'status' => 'active', 'admission_date' => today()]);
                                $year = AcademicYear::where('is_current', true)->first();
                                if ($year && $batch->class_section_id) Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $year->id, 'class_section_id' => $batch->class_section_id, 'enrolled_on' => today(), 'status' => 'active']);
                                if (! empty($data['guardian_name'])) {
                                    $guardian = Guardian::create(['first_name' => $data['guardian_name'], 'last_name' => $data['last_name'], 'phone' => $data['guardian_phone']]);
                                    $student->guardians()->attach($guardian->id, ['relationship' => 'guardian', 'is_primary' => true, 'is_emergency' => true]);
                                }
                                Audit::record('student_imported', $student, [], ['batch' => $batch->id, 'staff_id' => $user->id], null, 'Student imported');
                            });
                            $success++;
                        } catch (\Throwable $exception) { report($exception); $errors[] = ['row' => $index, 'errors' => ['Row could not be saved; check for a duplicate admission number.']]; }
                    }
                    $batch->update(['total_rows' => $total, 'success_rows' => $success, 'error_rows' => count($errors), 'errors' => array_slice($errors, 0, 1000)]);
                }
                break; // A student import uses the first worksheet only.
            }
            $reader->close();
            $batch->update(['status' => 'completed', 'errors' => $errors, 'total_rows' => $total, 'success_rows' => $success, 'error_rows' => count($errors)]);
        } catch (\Throwable $exception) {
            $reader->close(); report($exception); $batch->update(['status' => 'failed', 'errors' => [['row' => 0, 'errors' => ['Import failed. Check the workbook format and server logs.']]]]);
        }
    }
    public function failed(?\Throwable $exception): void { ImportBatch::whereKey($this->batchId)->update(['status' => 'failed']); }
}
