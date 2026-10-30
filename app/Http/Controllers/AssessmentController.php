<?php
namespace App\Http\Controllers;

use App\Models\{AssessmentComponent, GradeBand, GradingScale, Level, Setting};
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AssessmentController extends Controller
{
    public function index()
    {
        return Inertia::render('Academics/Assessment', [
            'levels' => Level::orderBy('sort_order')->get(),
            'components' => AssessmentComponent::where('is_active', true)->get(),
            'scales' => GradingScale::with('bands')->orderByDesc('id')->get(),
        ]);
    }
    public function store(Request $request)
    {
        $data = $request->validate([
            'level_id' => ['required', 'exists:levels,id'], 'name' => ['required', 'string', 'max:100'],
            'components' => ['required', 'array', 'min:1'], 'components.*.name' => ['required', 'string', 'max:80'],
            'components.*.code' => ['required', 'distinct', 'alpha_dash', 'max:30'], 'components.*.max_score' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'components.*.weight' => ['required', 'numeric', 'gt:0', 'max:100'], 'components.*.is_exam' => ['boolean'],
            'bands' => ['required', 'array', 'min:1'], 'bands.*.min_score' => ['required', 'numeric', 'between:0,100'],
            'bands.*.max_score' => ['required', 'numeric', 'between:0,100'], 'bands.*.grade' => ['required', 'string', 'max:10'], 'bands.*.remark' => ['required', 'string', 'max:200'],
        ]);
        if (abs(collect($data['components'])->sum('weight') - 100) > .001) throw \Illuminate\Validation\ValidationException::withMessages(['components' => 'Component weights must total 100%.']);
        $bands = collect($data['bands'])->sortBy('min_score')->values();
        $expected = 0;
        foreach ($bands as $band) {
            if (abs((float) $band['min_score'] - $expected) > .001 || $band['max_score'] < $band['min_score']) throw \Illuminate\Validation\ValidationException::withMessages(['bands' => 'Bands must cover 0–100 without overlaps or gaps (two decimal places).']);
            $expected = round((float) $band['max_score'] + .01, 2);
        }
        if ($expected !== 100.01) throw \Illuminate\Validation\ValidationException::withMessages(['bands' => 'The final band must end at 100.']);
        DB::transaction(function () use ($data) {
            $level = Level::findOrFail($data['level_id']);
            // Version component codes to retain foreign keys and prior result-sheet configurations.
            AssessmentComponent::where('level_id', $level->id)->update(['is_active' => false]);
            $revision = (int) AssessmentComponent::where('level_id', $level->id)->max('id') + 1;
            foreach ($data['components'] as $index => $component) AssessmentComponent::create([
                'level_id' => $level->id, 'curriculum' => $level->curriculum, 'name' => $component['name'],
                'code' => substr($component['code'], 0, 15).'-v'.$revision, 'max_score' => $component['max_score'], 'weight' => $component['weight'],
                'is_exam' => $component['is_exam'] ?? false, 'sort_order' => $index, 'is_active' => true,
            ]);
            $scale = GradingScale::create(['name' => $data['name'], 'curriculum' => $level->curriculum, 'is_default' => false]);
            foreach ($data['bands'] as $index => $band) $scale->bands()->create($band + ['sort_order' => $index]);
            Setting::set('grading_scale_level_'.$level->id, $scale->id, 'academics');
            Audit::record('assessment_configuration_versioned', $scale, [], ['level_id' => $level->id], null, 'Assessment configuration version created');
        });
        return back()->with('success', 'New assessment configuration saved. Existing scored sheets retain their configuration.');
    }
}
