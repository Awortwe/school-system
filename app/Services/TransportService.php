<?php
namespace App\Services;
use App\Models\{Student, Term, Invoice, FeeCategory, FeeItem};
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
class TransportService
{
    public function charges(Student $student, Term $term): array
    {
        $charges = [];
        foreach (DB::table('transport_enrollments')->where('student_id', $student->id)->where('start_date', '<=', $term->end_date)->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $term->start_date))->get() as $enrollment) {
            if (DB::table('transport_billings')->where('transport_enrollment_id', $enrollment->id)->where('term_id', $term->id)->exists()) continue;
            $fee = DB::table('transport_fees')->where('route_id', $enrollment->route_id)->where('term_id', $term->id)->where('status', 'approved')->whereIn('category', [$student->category, 'all'])->orderByRaw("CASE WHEN category = 'all' THEN 1 ELSE 0 END")->latest('id')->first();
            if (! $fee) continue;
            $route = DB::table('transport_routes')->find($enrollment->route_id);
            $charges[] = ['fee_item_id' => $fee->fee_item_id, 'description' => 'Transport: '.$route->name, 'amount_minor' => (int) $fee->amount_minor, 'transport_enrollment_id' => $enrollment->id];
        }
        return $charges;
    }
    public function bill(Student $student, Term $term, \App\Models\User $actor): void
    {
        DB::transaction(function () use ($student, $term, $actor) {
            Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::where('student_id', $student->id)->where('term_id', $term->id)->where('type', 'termly')->lockForUpdate()->first();
            if (! $invoice) { app(FinanceService::class)->invoiceForStudent($student, $term, $student->category, $actor); return; }
            abort_if($invoice->status === 'void', 422, 'Cannot add transport fees to a void invoice.');
            $charges = $this->charges($student, $term);
            abort_if(empty($charges), 422, 'No unbilled approved transport fees found for this student and term.');
            $total = 0;
            foreach ($charges as $charge) {
                $invoice->items()->create(['fee_item_id' => $charge['fee_item_id'], 'description' => $charge['description'], 'amount_minor' => $charge['amount_minor'], 'net_minor' => $charge['amount_minor'], 'discount_minor' => 0]);
                DB::table('transport_billings')->insert(['transport_enrollment_id' => $charge['transport_enrollment_id'], 'term_id' => $term->id, 'invoice_id' => $invoice->id, 'amount_minor' => $charge['amount_minor'], 'created_at' => now(), 'updated_at' => now()]); $total += $charge['amount_minor'];
            }
            $invoice->increment('subtotal_minor', $total); app(FinanceService::class)->recalculateInvoice($invoice);
            app(FinanceService::class)->postLedger($student, ['academic_year_id' => $term->academic_year_id, 'term_id' => $term->id, 'type' => 'transport_charge', 'reference' => $invoice->invoice_no, 'description' => 'Approved transport charges added to existing invoice', 'debit_minor' => $total, 'source_type' => Invoice::class, 'source_id' => $invoice->id, 'recorded_by' => $actor->id]);
            Audit::record('transport_fees_billed', $invoice, [], ['amount_minor' => $total], null, 'Transport fees posted to the core student ledger');
        });
    }
}
