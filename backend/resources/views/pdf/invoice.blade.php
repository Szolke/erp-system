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

{{-- Cím és számlaszám --}}
<div class="doc-title">
  @if($invoice->storno_of_invoice_id)
    {{ __('pdf.storno_invoice') }}
  @else
    {{ __('pdf.invoice') }}
  @endif
  <span class="doc-number">{{ $invoice->invoice_number }}</span>
</div>

{{-- Sztornó értesítő --}}
@if($invoice->storno_of_invoice_id && $invoice->stornoOf)
<div class="storno-notice">
  {{ __('pdf.storno_of') }}: {{ $invoice->stornoOf->invoice_number }}
</div>
@endif

{{-- Meta adatok --}}
<div class="meta-grid">
  <div class="meta-box">
    <div class="meta-box-title">{{ __('pdf.invoice') }}</div>
    <div class="meta-row"><span class="label">{{ __('pdf.issue_date') }}</span><span class="value">{{ $invoice->issue_date->format('Y-m-d') }}</span></div>
    <div class="meta-row"><span class="label">{{ __('pdf.fulfillment_date') }}</span><span class="value">{{ $invoice->fulfillment_date->format('Y-m-d') }}</span></div>
    <div class="meta-row"><span class="label">{{ __('pdf.due_date') }}</span><span class="value">{{ $invoice->due_date->format('Y-m-d') }}</span></div>
  </div>
  <div class="meta-box">
    <div class="meta-box-title">{{ __('pdf.payment_method') }}</div>
    <div class="meta-row">
      <span class="label">{{ __('pdf.payment_method') }}</span>
      <span class="value">{{ __('pdf.' . ($invoice->paymentMethod->code ?? 'bank_transfer'), [], null) ?: ($invoice->paymentMethod->name ?? '') }}</span>
    </div>
    <div class="meta-row"><span class="label">{{ $invoice->currency }}</span><span class="value">{{ number_format($invoice->gross_total, 2, ',', ' ') }}</span></div>
  </div>
</div>

{{-- Felek --}}
<div class="parties">
  <div class="party">
    <div class="party-title">{{ __('pdf.seller') }}</div>
    <div class="party-name">{{ $company->name }}</div>
    <div class="party-detail">
      {{ $company->postal_code }} {{ $company->city }}, {{ $company->address_line }}<br>
      {{ __('pdf.tax_number') }}: {{ $company->tax_number }}
    </div>
  </div>
  <div class="party">
    <div class="party-title">{{ __('pdf.buyer') }}</div>
    <div class="party-name">{{ $invoice->partner->name }}</div>
    <div class="party-detail">
      {{ $invoice->partner->billing_postal_code }} {{ $invoice->partner->billing_city }}, {{ $invoice->partner->billing_address_line }}<br>
      @if($invoice->partner->tax_number) {{ __('pdf.tax_number') }}: {{ $invoice->partner->tax_number }} @endif
    </div>
  </div>
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
    @foreach($invoice->items as $item)
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
      <span>{{ number_format($invoice->net_total, 2, ',', ' ') }} {{ $invoice->currency }}</span>
    </div>
    <div class="totals-row">
      <span>{{ __('pdf.vat_total') }}</span>
      <span>{{ number_format($invoice->vat_total, 2, ',', ' ') }} {{ $invoice->currency }}</span>
    </div>
    <div class="totals-row grand">
      <span>{{ __('pdf.gross_total') }}</span>
      <span>{{ number_format($invoice->gross_total, 2, ',', ' ') }} {{ $invoice->currency }}</span>
    </div>
  </div>
</div>

@if($company->invoice_header_text)
<div style="font-size:9px; color:#555; margin-bottom: 8px;">{{ $company->invoice_header_text }}</div>
@endif

@endsection
