<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\Student;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuardianController extends Controller
{
    public function store(Request $request, Student $student): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($student, $data, $request) {
            $guardian = Guardian::create(\Illuminate\Support\Arr::except($data, ['relationship', 'is_primary', 'is_emergency', 'custody']));

            $student->guardians()->attach($guardian->id, [
                'relationship' => $request->input('relationship', 'parent'),
                'is_primary' => $request->boolean('is_primary'),
                'is_emergency' => $request->boolean('is_emergency'),
                'custody' => $request->input('custody'),
            ]);

            if ($request->boolean('is_primary')) {
                $this->clearOtherPrimaries($student, $guardian->id);
            }

            Audit::record('guardian_created', $guardian, [], $guardian->only(['first_name', 'last_name', 'phone']), null, 'Guardian linked to student');
        });

        return back()->with('success', 'Guardian added.');
    }

    public function update(Request $request, Student $student, Guardian $guardian): RedirectResponse
    {
        abort_unless($student->guardians()->whereKey($guardian->id)->exists(), 404);

        $data = $this->validated($request);

        DB::transaction(function () use ($student, $guardian, $data, $request) {
            $guardian->update(\Illuminate\Support\Arr::except($data, ['relationship', 'is_primary', 'is_emergency', 'custody']));

            $student->guardians()->updateExistingPivot($guardian->id, [
                'relationship' => $request->input('relationship', 'parent'),
                'is_primary' => $request->boolean('is_primary'),
                'is_emergency' => $request->boolean('is_emergency'),
                'custody' => $request->input('custody'),
            ]);

            if ($request->boolean('is_primary')) {
                $this->clearOtherPrimaries($student, $guardian->id);
            }

            Audit::record('guardian_updated', $guardian, [], $guardian->only(['first_name', 'last_name', 'phone']), null, 'Guardian record updated');
        });

        return back()->with('success', 'Guardian updated.');
    }

    public function destroy(Request $request, Student $student, Guardian $guardian): RedirectResponse
    {
        abort_unless($student->guardians()->whereKey($guardian->id)->exists(), 404);

        $student->guardians()->detach($guardian->id);

        Audit::record('guardian_unlinked', $guardian, [], [], null, 'Guardian unlinked from student');

        return back()->with('success', 'Guardian removed from student.');
    }

    public function link(Request $request, Student $student): RedirectResponse
    {
        $data = $request->validate(['guardian_id' => ['required', 'exists:guardians,id'], 'relationship' => ['required', 'string', 'max:30']]);
        $student->guardians()->syncWithoutDetaching([$data['guardian_id'] => ['relationship' => $data['relationship']]]);
        Audit::record('guardian_linked', $student, [], $data, null, 'Existing guardian linked; siblings share guardian record');
        return back()->with('success', 'Guardian linked. Students sharing this guardian are linked as siblings.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'alt_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'employer' => ['nullable', 'string', 'max:150'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'relationship' => ['nullable', 'string', 'max:30'],
            'is_primary' => ['boolean'],
            'is_emergency' => ['boolean'],
            'custody' => ['nullable', 'string', 'max:30'],
        ]);
    }

    private function clearOtherPrimaries(Student $student, int $guardianId): void
    {
        foreach ($student->guardians()->whereKeyNot($guardianId)->get() as $other) {
            $student->guardians()->updateExistingPivot($other->id, ['is_primary' => false]);
        }
    }
}
