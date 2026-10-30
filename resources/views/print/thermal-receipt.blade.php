<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Receipt {{ $payment->receipt_no }}</title><style>
@page { size: auto; margin: 0; } * { box-sizing: border-box; } body { font-family: Arial, sans-serif; font-size: {{ $width === 58 ? 10 : 12 }}px; color: #000; width: {{ $width }}mm; margin: 0 auto; padding: 3mm; } h1 { font-size: {{ $width === 58 ? 14 : 17 }}px; margin: 0 0 2mm; } .center { text-align: center; } .rule { border-top: 1px dashed #000; margin: 3mm 0; } p { margin: 1.5mm 0; overflow-wrap: anywhere; } table { width: 100%; table-layout: fixed; border-collapse: collapse; } td { padding: 1.5mm 0; vertical-align: top; overflow-wrap: anywhere; } td:last-child { text-align: right; width: 25mm; } .total { font-size: 1.15em; font-weight: bold; } .status { font-size: 1.2em; font-weight: bold; border: 2px solid #000; padding: 2mm; } .controls { background: #eee; padding: 3mm; margin-bottom: 3mm; font-size: 11px; } @media print { .controls { display:none; } body { margin:0; } }
</style></head><body><div class="controls"><p>Printer paper: {{ $width }} mm. Set printer width to match, scale 100%, margins none and disable browser headers/footers.</p><button onclick="window.print()">Print receipt</button><p>Uses your browser and operating-system printer dialog. No direct USB/Bluetooth control.</p></div><div class="center"><h1>{{ $school['name'] }}</h1><p>{{ $school['address'] }}</p><p>{{ $school['phone'] }}<br>{{ $school['email'] }}</p><div class="rule"></div><strong>PAYMENT RECEIPT @if($copy) - COPY @endif</strong><p>{{ $payment->receipt_no }}</p></div>
@if($payment->status === 'reversed')
<p class="status center">REVERSED</p><p>{{ $payment->reversal_reason }}</p>
@endif
@php
    $snapshot = $payment->receipt_snapshot ?? [];
    $receiptAllocations = $snapshot['allocations'] ?? $payment->allocations->map(fn ($allocation) => ['invoice_no' => $allocation->invoice?->invoice_no, 'amount_minor' => $allocation->amount_minor, 'items' => $allocation->invoice?->items->pluck('description')->all() ?? []])->all();
    $unallocated = $snapshot['unallocated_minor'] ?? $payment->unallocated_minor;
@endphp
<p><strong>{{ $snapshot['student_name'] ?? $payment->student?->fullName() }}</strong></p><p>{{ $snapshot['admission_no'] ?? $payment->student?->admission_no }}<br>{{ $snapshot['class'] ?? $payment->student?->currentSection?->displayName() }}</p><p>{{ $snapshot['year'] ?? '' }} · {{ $snapshot['term'] ?? '' }}</p><p>Date: {{ $payment->payment_date?->toDateString() }}</p><div class="rule"></div><table>
@foreach($receiptAllocations as $allocation)
<tr><td>{{ $allocation['invoice_no'] }}
@foreach($allocation['items'] as $item)
<br>{{ $item }}
@endforeach
</td><td>{{ \App\Support\Money::toMajor($allocation['amount_minor']) }}</td></tr>
@endforeach
@if($unallocated > 0)
<tr><td>Unallocated credit at payment</td><td>{{ \App\Support\Money::toMajor($unallocated) }}</td></tr>
@endif
<tr class="total"><td>PAID (GHS)</td><td>{{ \App\Support\Money::toMajor($payment->amount_minor) }}</td></tr></table><div class="rule"></div><p>Method: {{ str_replace('_', ' ', $payment->method) }}</p><p>Reference: {{ $payment->transaction_reference ?: 'Not supplied' }}</p><p>Recorded by: {{ $snapshot['recorded_by'] ?? $payment->recordedBy?->name }}</p>
@if(array_key_exists('balance_minor', $snapshot))
<p>Account balance after payment: <strong>{{ \App\Support\Money::format($snapshot['balance_minor']) }}</strong></p><p>Positive: owed. Negative: credit.</p>
@else
<p>Historical balance unavailable. See account statement.</p>
@endif
<div class="rule"></div><p class="center">Retain this receipt for your records.</p></body></html>
