@extends('pdf.finance-layout')
@section('content')
@php
    $snapshot = $payment->receipt_snapshot ?? [];
    $receiptAllocations = $snapshot['allocations'] ?? $payment->allocations->map(fn ($allocation) => ['invoice_no' => $allocation->invoice?->invoice_no, 'amount_minor' => $allocation->amount_minor, 'items' => $allocation->invoice?->items->pluck('description')->all() ?? []])->all();
    $unallocated = $snapshot['unallocated_minor'] ?? $payment->unallocated_minor;
@endphp
<h2>PAYMENT RECEIPT {{ $payment->receipt_no }} @if($copy) - COPY @endif</h2>
@if($is_reversed)
<p class="reversed">REVERSED - NOT VALID AS PAYMENT</p><p>Reason: {{ $payment->reversal_reason }}</p>
@endif
<p><strong>{{ $snapshot['student_name'] ?? $payment->student?->fullName() }}</strong><br>Admission no: {{ $snapshot['admission_no'] ?? $payment->student?->admission_no }} | Class: {{ $snapshot['class'] ?? $payment->student?->currentSection?->displayName() }}<br>{{ $snapshot['year'] ?? $payment->term?->academicYear?->name }} | {{ $snapshot['term'] ?? $payment->term?->name }}<br>Payment date: {{ $payment->payment_date?->toDateString() }}</p>
<table><thead><tr><th style="width:65%">Payment details at confirmation</th><th class="amount">GHS</th></tr></thead><tbody>
@foreach($receiptAllocations as $allocation)
<tr><td>{{ $allocation['invoice_no'] }}
@foreach($allocation['items'] as $item)
<br><span class="muted">{{ $item }}</span>
@endforeach
</td><td class="amount">{{ \App\Support\Money::toMajor($allocation['amount_minor']) }}</td></tr>
@endforeach
@if($unallocated > 0)
<tr><td>Unallocated student credit at payment</td><td class="amount">{{ \App\Support\Money::toMajor($unallocated) }}</td></tr>
@endif
<tr class="total"><td>Total amount paid</td><td class="amount">{{ \App\Support\Money::toMajor($payment->amount_minor) }}</td></tr></tbody></table>
<p>Method: {{ ucwords(str_replace('_', ' ', $payment->method)) }}<br>Transaction reference: {{ $payment->transaction_reference ?: 'Not supplied' }}<br>Recorded by: {{ $snapshot['recorded_by'] ?? $payment->recordedBy?->name }}</p>
@if(array_key_exists('balance_minor', $snapshot))
<div class="notice">Student account balance immediately after payment: <strong>{{ \App\Support\Money::format($snapshot['balance_minor']) }}</strong><br>Positive balance is owed to the school; negative balance is student credit. Later allocations and transactions are shown on the account statement.</div>
@else
<div class="notice">Historical balance was not captured for this legacy receipt. See the account statement for the current balance.</div>
@endif
@endsection
