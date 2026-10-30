<?php

namespace App\Http\Controllers;

use App\Models\FeeCategory;
use App\Models\FeeItem;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\Invoice;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\Term;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FeeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Finance/Fees/Index', [
            'categories' => FeeCategory::with('items')->orderBy('name')->get(),
            'feeItems' => FeeItem::with('feeCategory')->orderBy('name')->get(),
            'structures' => FeeStructure::with(['term.academicYear', 'level', 'schoolClass', 'items.feeItem'])
                ->latest('id')
                ->get()
                ->map(fn (FeeStructure $structure) => [
                    'id' => $structure->id,
                    'name' => $structure->name,
                    'term_id' => $structure->term_id,
                    'term' => $structure->term?->name,
                    'year' => $structure->term?->academicYear?->name,
                    'level_id' => $structure->level_id,
                    'level' => $structure->level?->name,
                    'school_class_id' => $structure->school_class_id,
                    'class' => $structure->schoolClass?->name,
                    'category' => $structure->category,
                    'status' => $structure->status,
                    'items' => $structure->items->map(fn (FeeStructureItem $item) => [
                        'id' => $item->id,
                        'fee_item_id' => $item->fee_item_id,
                        'name' => $item->feeItem?->name,
                        'amount_minor' => $item->amount_minor,
                    ]),
                    'total_minor' => (int) $structure->items->sum('amount_minor'),
                ]),
            'terms' => Term::with('academicYear')->orderByDesc('academic_year_id')->orderBy('sequence')->get()->map(fn (Term $term) => [
                'id' => $term->id,
                'name' => ($term->academicYear?->name ? $term->academicYear->name.' - ' : '').$term->name,
            ]),
            'levels' => Level::orderBy('sort_order')->get(['id', 'name']),
            'classes' => SchoolClass::orderBy('name')->get(['id', 'name', 'level_id']),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:fee_categories,name'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        FeeCategory::create($data);

        return back()->with('success', 'Fee category created.');
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fee_category_id' => ['nullable', 'exists:fee_categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', 'unique:fee_items,code'],
            'is_tuition' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        FeeItem::create($data);

        return back()->with('success', 'Fee item created.');
    }

    public function updateItem(Request $request, FeeItem $feeItem): RedirectResponse
    {
        $data = $request->validate([
            'fee_category_id' => ['nullable', 'exists:fee_categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', Rule::unique('fee_items', 'code')->ignore($feeItem->id)],
            'is_tuition' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $feeItem->update($data);

        return back()->with('success', 'Fee item updated.');
    }

    public function destroyItem(FeeItem $feeItem): RedirectResponse
    {
        if (FeeStructureItem::where('fee_item_id', $feeItem->id)->exists()) {
            return back()->with('error', 'This fee item is used in a fee structure and cannot be deleted.');
        }

        $feeItem->delete();

        return back()->with('success', 'Fee item deleted.');
    }

    public function storeStructure(Request $request): RedirectResponse
    {
        $data = $this->validateStructure($request);
        $term = Term::findOrFail($data['term_id']);

        DB::transaction(function () use ($data, $term) {
            $structure = FeeStructure::create([
                'academic_year_id' => $term->academic_year_id,
                'term_id' => $term->id,
                'level_id' => $data['level_id'] ?? null,
                'school_class_id' => $data['school_class_id'] ?? null,
                'category' => $data['category'],
                'name' => $data['name'],
                'status' => $data['status'] ?? 'active',
            ]);

            $this->syncItems($structure, $data['items']);

            Audit::record('fee_structure_created', $structure, [], ['name' => $structure->name], null, 'Fee structure created');
        });

        return back()->with('success', 'Fee structure created.');
    }

    public function updateStructure(Request $request, FeeStructure $feeStructure): RedirectResponse
    {
        $data = $this->validateStructure($request);
        $term = Term::findOrFail($data['term_id']);

        DB::transaction(function () use ($feeStructure, $data, $term) {
            $feeStructure->update([
                'academic_year_id' => $term->academic_year_id,
                'term_id' => $term->id,
                'level_id' => $data['level_id'] ?? null,
                'school_class_id' => $data['school_class_id'] ?? null,
                'category' => $data['category'],
                'name' => $data['name'],
                'status' => $data['status'] ?? 'active',
            ]);

            $feeStructure->items()->delete();
            $this->syncItems($feeStructure, $data['items']);

            Audit::record('fee_structure_updated', $feeStructure, [], ['name' => $feeStructure->name], null, 'Fee structure updated');
        });

        return back()->with('success', 'Fee structure updated.');
    }

    public function destroyStructure(FeeStructure $feeStructure): RedirectResponse
    {
        if (Invoice::where('fee_structure_id', $feeStructure->id)->exists()) {
            return back()->with('error', 'This fee structure has been used on invoices and cannot be deleted. Set it to inactive instead.');
        }

        $feeStructure->delete();

        return back()->with('success', 'Fee structure deleted.');
    }

    private function validateStructure(Request $request): array
    {
        return $request->validate([
            'term_id' => ['required', 'exists:terms,id'],
            'level_id' => ['nullable', 'exists:levels,id'],
            'school_class_id' => ['nullable', 'exists:school_classes,id'],
            'category' => ['required', Rule::in(['all', 'day', 'boarding'])],
            'name' => ['required', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fee_item_id' => ['required', 'exists:fee_items,id'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
        ]);
    }

    private function syncItems(FeeStructure $structure, array $items): void
    {
        foreach ($items as $item) {
            $structure->items()->create([
                'fee_item_id' => $item['fee_item_id'],
                'amount_minor' => Money::fromMajor($item['amount']),
            ]);
        }
    }
}
