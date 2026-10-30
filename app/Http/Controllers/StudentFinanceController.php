<?php

namespace App\Http\Controllers;

use App\Models\Refund;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentLedgerEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class StudentFinanceController extends Controller
{
    public function statement(Student $student): Response
    {
        $student->load(['currentSection.schoolClass.level', 'guardians']);

        return Inertia::render('Finance/Statements/Show', [
            'student' => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'admission_no' => $student->admission_no,
                'class' => $student->currentSection?->displayName(),
                'level' => $student->currentSection?->schoolClass?->level?->name,
                'category' => $student->category,
                'status' => $student->status,
                'guardian' => $student->guardians->firstWhere('pivot.is_primary', true)?->fullName(),
                'phone' => $student->guardians->firstWhere('pivot.is_primary', true)?->phone,
            ],
            'balance_minor' => $this->balance($student),
            'ledger' => StudentLedgerEntry::with('recordedBy')
                ->where('student_id', $student->id)
                ->orderBy('id')
                ->get()
                ->map(fn (StudentLedgerEntry $entry) => [
                    'id' => $entry->id,
                    'entry_date' => $entry->entry_date?->toDateString(),
                    'type' => $entry->type,
                    'reference' => $entry->reference,
                    'description' => $entry->description,
                    'debit_minor' => $entry->debit_minor,
                    'credit_minor' => $entry->credit_minor,
                    'balance_minor' => $entry->balance_minor,
                    'recorded_by' => $entry->recordedBy?->name,
                ]),
            'invoices' => $student->invoices()->with('term.academicYear')->latest('issue_date')->get()->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'term' => $invoice->term?->name,
                'year' => $invoice->term?->academicYear?->name,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total_minor' => $invoice->total_minor,
                'paid_minor' => $invoice->paid_minor,
                'balance_minor' => $invoice->balance_minor,
                'status' => $invoice->status,
            ]),
            'payments' => $student->payments()->with('term')->where('status', 'confirmed')->latest('payment_date')->get()->map(fn ($payment) => [
                'id' => $payment->id,
                'receipt_no' => $payment->receipt_no,
                'term' => $payment->term?->name,
                'payment_date' => $payment->payment_date?->toDateString(),
                'amount_minor' => $payment->amount_minor,
                'unallocated_minor' => $payment->unallocated_minor,
                'method' => $payment->method,
                'reference' => $payment->transaction_reference,
            ]),
            'refunds' => Refund::where('student_id', $student->id)->latest('id')->get()->map(fn (Refund $refund) => [
                'id' => $refund->id,
                'refund_no' => $refund->refund_no,
                'amount_minor' => $refund->amount_minor,
                'method' => $refund->method,
                'reason' => $refund->reason,
                'status' => $refund->status,
                'created_at' => $refund->created_at?->toDateString(),
            ]),
            'credit_minor' => max(0, -$this->balance($student)),
        ]);
    }

    public function statementPdf(Student $student): HttpResponse
    {
        $student->load(['currentSection.schoolClass.level', 'guardians']);

        $balance = $this->balance($student);

        return Pdf::loadView('pdf.statement', [
            'student' => $student,
            'school' => Setting::school(),
            'balance_minor' => $balance,
            'ledger' => StudentLedgerEntry::where('student_id', $student->id)->orderBy('id')->get(),
            'invoices' => $student->invoices()->with('term.academicYear')->latest('issue_date')->get(),
            'payments' => $student->payments()->with('term')->where('status', 'confirmed')->latest('payment_date')->get(),
        ])->setPaper('a4', 'portrait')->setOption([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ])->stream('statement-'.$student->admission_no.'.pdf');
    }

    private function balance(Student $student): int
    {
        return (int) (StudentLedgerEntry::where('student_id', $student->id)->orderByDesc('id')->value('balance_minor') ?? 0);
    }
}
