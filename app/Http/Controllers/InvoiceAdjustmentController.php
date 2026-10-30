<?php

namespace App\Http\Controllers;

use App\Models\InvoiceAdjustment;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceAdjustmentController extends Controller
{
    public function __construct(private readonly FinanceService $finance)
    {
    }

    public function index(Request $request): Response
    {
        $status = $request->get('status', 'pending');

        return Inertia::render('Finance/Adjustments/Index', [
            'adjustments' => InvoiceAdjustment::with(['invoice.student.currentSection.schoolClass', 'requestedBy', 'approvedBy'])
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->latest('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (InvoiceAdjustment $adjustment) => [
                    'id' => $adjustment->id,
                    'invoice_id' => $adjustment->invoice_id,
                    'invoice_no' => $adjustment->invoice?->invoice_no,
                    'student' => $adjustment->invoice?->student?->fullName(),
                    'class' => $adjustment->invoice?->student?->currentSection?->displayName(),
                    'type' => $adjustment->type,
                    'amount_minor' => $adjustment->amount_minor,
                    'reason' => $adjustment->reason,
                    'status' => $adjustment->status,
                    'requested_by' => $adjustment->requestedBy?->name,
                    'approved_by' => $adjustment->approvedBy?->name,
                    'created_at' => $adjustment->created_at?->toDateTimeString(),
                ]),
            'status' => $status,
        ]);
    }

    public function approve(Request $request, InvoiceAdjustment $adjustment): RedirectResponse
    {
        $this->finance->approveAdjustment($adjustment, $request->user());

        return back()->with('success', 'Adjustment approved and applied to the invoice.');
    }

    public function reject(Request $request, InvoiceAdjustment $adjustment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->finance->rejectAdjustment($adjustment, $request->user(), $data['reason']);

        return back()->with('success', 'Adjustment rejected.');
    }
}
