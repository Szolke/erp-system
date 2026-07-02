@extends('pdf.layout')

@section('content')

{{-- Fejléc --}}
<div class="header">
  <div class="company-block">
    <div class="company-name">{{ $company->name }}</div>
    <div class="company-detail">
      {{ $company->postal_code }} {{ $company->city }}, {{ $company->address_line }}<br>
      {{ __('pdf.tax_number') }}: {{ $company->tax_number }}
      @if($company->eu_tax_number) &nbsp;|&nbsp; EU: {{ $company->eu_tax_number }} @endif<br>
      @if($company->email) {{ $company->email }} @endif
      @if($company->phone) &nbsp;|&nbsp; {{ $company->phone }} @endif
    </div>
  </div>
  @if($logoData)
  <div class="logo-block">
    <img src="{{ $logoData }}" alt="logo">
  </div>
  @endif
</div>

{{-- Cím --}}
<div class="doc-title">
  @if($receipt->storno_of_receipt_id)
    {{ __('pdf.storno_receipt') }}
  @else
    {{ __('pdf.receipt') }}
  @endif
  <span class="doc-number">{{ $receipt->receipt_number }}</span>
</div>

@if($receipt->storno_of_receipt_id && $receipt->stornoOf)
<div class="storno-notice">
  {{ __('pdf.storno_of') }}: {{ $receipt->stornoOf->receipt_number }}
</div>
@endif

{{-- Meta --}}
<div class="meta-grid">
  <div class="meta-box">
    <div class="meta-box-title">{{ __('pdf.receipt') }}</div>
    <div class="meta-row"><span class="label">{{ __('pdf.issue_date') }}</span><span class="value">{{ $receipt->issue_date->format('Y-m-d') }}</span></div>
    <div class="meta-row">
      <span class="label">{{ __('pdf.payment_method') }}</span>
      <span class="value">{{ __('pdf.' . ($receipt->paymentMethod->code ?? 'cash'), [], null) ?: ($receipt->paymentMethod->name ?? '') }}</span>
    </div>
  </div>
  @if($receipt->partner)
  <div class="meta-box">
    <div class="meta-box-title">{{ __('pdf.buyer') }}</div>
    <div class="meta-row"><span class="label">{{ $receipt->partner->name }}</span></div>
    @if($receipt->partner->tax_number)
    <div class="meta-row"><span class="label">{{ __('pdf.tax_number') }}</span><span class="value">{{ $receipt->partner->tax_number }}</span></div>
    @endif
  </div>
  @endif
</div>

{{-- Tételek --}}
<table>
  <thead>
    <tr>
      <th>{{ __('pdf.description') }}</th>
      <th class="right" style="width:50px">{{ __('pdf.quantity') }}</th>
      <th style="width:35px">{{ __('pdf.unit') }}</th>
      <th class="right" style="width:75px">{{ __('pdf.unit_price') }}</th>
      <th style="width:40px">{{ __('pdf.vat_rate') }}</th>
      <th class="right" style="width:75px">{{ __('pdf.net_amount') }}</th>
      <th class="right" style="width:75px">{{ __('pdf.gross_amount') }}</th>
    </tr>
  </thead>
  <tbody>
    @foreach($receipt->items as $item)
    <tr>
      <td>{{ $item->description }}</td>
      <td class="right">{{ rtrim(rtrim(number_format($item->quantity, 3, ',', ' '), '0'), ',') }}</td>
      <td>{{ $item->unit }}</td>
      <td class="right">{{ number_format($item->unit_price, 2, ',', ' ') }}</td>
      <td>{{ $item->vatRate->name }}</td>
      <td class="right">{{ number_format($item->net_amount, 2, ',', ' ') }}</td>
      <td class="right">{{ number_format($item->gross_amount, 2, ',', ' ') }}</td>
    </tr>
    @endforeach
  </tbody>
</table>

{{-- Összesítő --}}
<div class="totals">
  <div class="totals-box">
    <div class="totals-row">
      <span>{{ __('pdf.net_total') }}</span>
      <span>{{ number_format($receipt->net_total, 2, ',', ' ') }} {{ $receipt->currency }}</span>
    </div>
    <div class="totals-row">
      <span>{{ __('pdf.vat_total') }}</span>
      <span>{{ number_format($receipt->vat_total, 2, ',', ' ') }} {{ $receipt->currency }}</span>
    </div>
    <div class="totals-row grand">
      <span>{{ __('pdf.gross_total') }}</span>
      <span>{{ number_format($receipt->gross_total, 2, ',', ' ') }} {{ $receipt->currency }}</span>
    </div>
  </div>
</div>

@endsection
