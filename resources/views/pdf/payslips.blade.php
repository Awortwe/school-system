@extends('pdf.finance-layout')
@section('content')
@foreach($entries as $entry)
<div style="page-break-before: {{ $loop->first ? 'auto' : 'always' }}">
<h2>CONFIDENTIAL PAYSLIP - {{ $period->name }}</h2><p><strong>{{ $entry->details['staff_name'] }}</strong><br>Employee: {{ $entry->details['employee_no'] ?? '-' }} | Department: {{ $entry->details['department'] ?? '-' }}<br>Period: {{ $period->start_date }} to {{ $period->end_date }}</p>
<table><thead><tr><th>Component</th><th class="amount">GHS</th></tr></thead><tbody><tr><td>Basic salary</td><td class="amount">{{ \App\Support\Money::toMajor($entry->details['basic_minor']) }}</td></tr>
@foreach($entry->details['components'] as $component)
<tr><td>{{ $component['name'] }} ({{ $component['type'] }})</td><td class="amount">{{ \App\Support\Money::toMajor($component['amount_minor']) }}</td></tr>
@endforeach
<tr><td>Gross earnings</td><td class="amount">{{ \App\Support\Money::toMajor($entry->gross_minor) }}</td></tr><tr><td>Employee pension deduction</td><td class="amount">{{ \App\Support\Money::toMajor($entry->details['employee_pension_minor']) }}</td></tr><tr><td>PAYE</td><td class="amount">{{ \App\Support\Money::toMajor($entry->details['paye_minor']) }}</td></tr><tr class="total"><td>Net salary before approved corrections</td><td class="amount">{{ \App\Support\Money::toMajor($entry->net_minor) }}</td></tr>
@foreach($entry->adjustments as $adjustment)
<tr><td>Approved correction: {{ $adjustment->reason }}</td><td class="amount">{{ \App\Support\Money::toMajor($adjustment->net_delta_minor) }}</td></tr>
@endforeach
<tr><td>Salary payments recorded</td><td class="amount">{{ \App\Support\Money::toMajor($entry->paid_minor) }}</td></tr><tr class="total"><td>Salary outstanding</td><td class="amount">{{ \App\Support\Money::toMajor($entry->net_minor + $entry->adjustments->sum('net_delta_minor') - $entry->paid_minor) }}</td></tr><tr><td>Employer contributions (separate from employee deductions)</td><td class="amount">{{ \App\Support\Money::toMajor($entry->employer_minor) }}</td></tr></tbody></table></div>
@endforeach
@endsection
