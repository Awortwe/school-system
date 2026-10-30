@extends('pdf.finance-layout')
@section('content')<h2>{{ $title }}</h2><p>All amounts in GHS. Generated {{ now()->format('d M Y H:i') }}.</p><table><thead><tr>@foreach($headers as $header)<th>{{ ucwords(str_replace('_', ' ', $header)) }}</th>@endforeach</tr></thead><tbody>@foreach($rows as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@endforeach</tbody></table>@endsection
