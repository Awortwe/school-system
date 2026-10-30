<?php
namespace App\Http\Controllers;

use App\Models\{CashbookEntry, Invoice, Payment, Setting};
use App\Support\Audit;
use Illuminate\Http\Request;

class FinanceExportController extends Controller
{
    public function reconcile(Request $request, CashbookEntry $entry)
    {
        abort_unless(\App\Support\PayrollPrivacy::cashbook(CashbookEntry::query(), $request->user())->whereKey($entry->id)->exists(), 403);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $entry) {
            $entry = CashbookEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->reconciled_at) return;
            $entry->update(['reconciled_at' => now(), 'reconciled_by' => $request->user()->id]);
            Audit::record('cashbook_reconciled', $entry, [], ['reconciled_at' => now()->toDateTimeString()], null, 'Cashbook entry checked against supporting records');
        });
        return back()->with('success', 'Cashbook entry marked reconciled.');
    }
    public function export(Request $request, string $type)
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'format' => ['nullable', 'in:csv,xlsx,pdf']]);
        $rows = match ($type) {
            'cashbook' => \App\Support\PayrollPrivacy::cashbook(CashbookEntry::query(), $request->user())->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('entry_date', '>=', $date))->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('entry_date', '<=', $date))->orderBy('id')->get(['entry_date', 'reference', 'description', 'method', 'inflow_minor', 'outflow_minor', 'reconciled_at'])->toArray(),
            'payments' => Payment::with('student')->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('payment_date', '>=', $date))->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('payment_date', '<=', $date))->get()->map(fn ($payment) => ['receipt' => $payment->receipt_no, 'student' => $payment->student?->fullName(), 'date' => $payment->payment_date?->toDateString(), 'method' => $payment->method, 'amount_minor' => $payment->amount_minor, 'status' => $payment->status])->all(),
            'debtors' => Invoice::with('student')->whereIn('status', ['issued', 'partially_paid'])->get()->groupBy('student_id')->map(fn ($invoices) => ['student' => $invoices->first()->student?->fullName(), 'admission_no' => $invoices->first()->student?->admission_no, 'outstanding_minor' => $invoices->sum('balance_minor')])->values()->all(),
            default => abort(404),
        };
        $headers = array_keys($rows[0] ?? ['message' => '']);
        foreach ($rows as &$row) foreach ($row as $key => &$value) {
            if (str_ends_with($key, '_minor')) $value = \App\Support\Money::toMajor((int) $value);
            elseif (is_string($value) && preg_match('/^[=+@\-\t\r]/', $value)) $value = "'".$value;
        }
        unset($row, $value);
        Audit::record('financial_export', null, [], ['type' => $type, 'rows' => count($rows)], null, 'Operational financial report exported');
        if (($data['format'] ?? '') === 'pdf') return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.financial-export', ['school' => Setting::school(), 'headers' => $headers, 'rows' => $rows, 'title' => ucfirst($type).' operational report'])->setPaper('a4', 'landscape')->stream($type.'.pdf');
        if (($data['format'] ?? '') === 'xlsx') {
            $path = tempnam(sys_get_temp_dir(), 'tss-export-');
            $writer = new \OpenSpout\Writer\XLSX\Writer(); $writer->openToFile($path);
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers));
            foreach ($rows as $row) $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(array_values($row)));
            $writer->close(); return response()->download($path, $type.'.xlsx')->deleteFileAfterSend(true);
        }
        return response()->streamDownload(function () use ($headers, $rows) { $file = fopen('php://output', 'w'); fputcsv($file, $headers, ',', '"', ''); foreach ($rows as $row) fputcsv($file, $row, ',', '"', ''); fclose($file); }, $type.'.csv', ['Content-Type' => 'text/csv']);
    }
}

