<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
  .page { padding: 28px 32px; }

  /* Header */
  .header { display: flex; justify-content: space-between; margin-bottom: 24px; border-bottom: 2px solid #1e3a5f; padding-bottom: 14px; }
  .company-block { flex: 1; }
  .company-name { font-size: 14px; font-weight: bold; color: #1e3a5f; margin-bottom: 4px; }
  .company-detail { font-size: 9px; color: #555; line-height: 1.6; }
  .logo-block { text-align: right; }
  .logo-block img { max-height: 56px; max-width: 160px; }

  /* Title */
  .doc-title { font-size: 20px; font-weight: bold; color: #1e3a5f; text-align: right; margin-bottom: 18px; }
  .doc-title .doc-number { font-size: 12px; color: #444; font-weight: normal; display: block; margin-top: 2px; }

  /* Meta grid */
  .meta-grid { display: flex; gap: 20px; margin-bottom: 20px; }
  .meta-box { flex: 1; background: #f4f7fb; border-radius: 4px; padding: 10px 12px; }
  .meta-box-title { font-size: 8px; text-transform: uppercase; color: #777; letter-spacing: 0.5px; margin-bottom: 6px; font-weight: bold; }
  .meta-row { display: flex; justify-content: space-between; font-size: 9px; line-height: 1.7; }
  .meta-row .label { color: #666; }
  .meta-row .value { font-weight: bold; color: #1a1a1a; }

  /* Parties */
  .parties { display: flex; gap: 20px; margin-bottom: 20px; }
  .party { flex: 1; border: 1px solid #dde3ec; border-radius: 4px; padding: 10px 12px; }
  .party-title { font-size: 8px; text-transform: uppercase; color: #777; letter-spacing: 0.5px; margin-bottom: 6px; font-weight: bold; }
  .party-name { font-size: 11px; font-weight: bold; color: #1e3a5f; margin-bottom: 4px; }
  .party-detail { font-size: 9px; color: #555; line-height: 1.6; }

  /* Items table */
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  thead tr { background: #1e3a5f; color: white; }
  thead th { padding: 7px 8px; text-align: left; font-size: 9px; font-weight: bold; }
  thead th.right { text-align: right; }
  tbody tr { border-bottom: 1px solid #e8edf5; }
  tbody tr:nth-child(even) { background: #f9fbfd; }
  tbody td { padding: 6px 8px; font-size: 9px; vertical-align: top; }
  tbody td.right { text-align: right; }

  /* Totals */
  .totals { display: flex; justify-content: flex-end; margin-bottom: 20px; }
  .totals-box { width: 260px; }
  .totals-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 9px; border-bottom: 1px solid #e8edf5; }
  .totals-row.grand { font-size: 11px; font-weight: bold; color: #1e3a5f; border-top: 2px solid #1e3a5f; border-bottom: none; padding-top: 6px; margin-top: 2px; }

  /* Storno notice */
  .storno-notice { background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 8px 12px; margin-bottom: 16px; font-size: 9px; color: #856404; }

  /* Footer */
  .footer { margin-top: 24px; border-top: 1px solid #dde3ec; padding-top: 10px; font-size: 8px; color: #888; }
</style>
</head>
<body>
<div class="page">
  @yield('content')
  @if($company->invoice_footer_text)
  <div class="footer">{{ $company->invoice_footer_text }}</div>
  @endif
</div>
</body>
</html>
