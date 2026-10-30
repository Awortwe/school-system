<?php
namespace App\Http\Controllers;
use App\Models\{ClassSection, Setting, Student};
class ClassListController extends Controller
{
    public function show(ClassSection $section) {
        $students = Student::where('current_section_id', $section->id)->where('status', 'active')->orderBy('last_name')->get()->map(fn ($student) => ['admission_no' => $student->admission_no, 'student' => $student->fullName(), 'gender' => $student->gender])->all();
        return \App\Support\TabularExport::response($students, 'Class list · '.$section->displayName(), request()->input('format') === 'csv' ? 'csv' : 'pdf');
    }
}
