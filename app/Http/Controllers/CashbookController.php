<?php

namespace App\Http\Controllers;

use App\Models\CashbookEntry;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashbookController extends Controller
{
    public function index(Request $request): Response
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $type = $request->get('type');
        $method = $request->get('method');

        $query = \App\Support\PayrollPrivacy::cashbook(CashbookEntry::query(), $request->user())->with('recordedBy')
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->when($type, fn ($query, $type) => $query->where('type', $type))
            ->when($method, fn ($query, $method) => $query->where('method', $method));

        $totals = (clone $query)->selectRaw('COALESCE(SUM(inflow_minor),0) as inflow, COALESCE(SUM(outflow_minor),0) as outflow')->first();

        $entries = $query->orderByDesc('entry_date')->orderByDesc('id')->paginate(30)->withQueryString()->through(fn (CashbookEntry $entry) => [
            'id' => $entry->id,
            'entry_date' => $entry->entry_date?->toDateString(),
            'type' => $entry->type,
            'reference' => $entry->reference,
            'description' => $entry->description,
            'method' => $entry->method,
            'inflow_minor' => $entry->inflow_minor,
            'outflow_minor' => $entry->outflow_minor,
            'recorded_by' => $entry->recordedBy?->name,
            'reconciled' => $entry->reconciled_at !== null,
        ]);

        return Inertia::render('Finance/Cashbook/Index', [
            'entries' => $entries,
            'salaryEntriesRestricted' => ! $request->user()->can('salaries.view'),
            'filters' => ['from' => $from, 'to' => $to, 'type' => $type, 'method' => $method],
            'totals' => [
                'inflow' => (int) ($totals->inflow ?? 0),
                'outflow' => (int) ($totals->outflow ?? 0),
                'balance' => (int) ($totals->inflow ?? 0) - (int) ($totals->outflow ?? 0),
            ],
            'types' => CashbookEntry::distinct()->orderBy('type')->pluck('type'),
            'methods' => collect(Setting::get('payment_methods', ['cash', 'momo', 'bank_transfer', 'cheque']))
                ->map(fn ($method) => ['value' => $method, 'label' => ucfirst(str_replace('_', ' ', $method))])
                ->values(),
        ]);
    }
}
