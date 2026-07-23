# 9. Reports

The sidebar's **Reports** menu item shows summary views of invoicing data across four tabs:
invoices, products, receivables, and VAT. The module can be enabled or disabled per company —
if you don't see the menu item, it isn't enabled for your company, or you don't have
permission to access it.
Back: [README.md](../README.md)

---

## Common Filter Bar

The Invoices, Products, and VAT Summary tabs share the same filter bar: a date range picker,
a **"By fulfillment date / By issue date"** toggle (which date determines the period), and
quick-pick pills (This month, Last month, This year, Last year). The **Receivables tab uses a
different filter bar**: instead of a range, it has a single "As of" field — receivables are
always evaluated as of a given day, not over a period.

> Viewing reports and exporting them require separate permissions — you may be able to see a
> report but not the **Export to CSV** button.

---

## Invoices

KPI cards (net, VAT, gross, outstanding amount), a monthly or daily bar chart, and a table
broken down by period. Filterable by partner and payment status (open / partially paid /
paid).

> **Important:** by default this tab shows invoices ONLY — receipts are not included,
> despite the tab's name suggesting otherwise. Turning on **"Include receipts"** extends
> both the chart and the table with receipt data (receipt count, receipt gross total).

---

## Products

A table of best-selling products/services: quantity, net revenue, invoice count, average
unit price, and share of total revenue. Paginated. Sortable by revenue or quantity.

> The chart at the top of the tab **always shows the top 10 products by revenue**,
> regardless of how you sort the table below it — sorting by quantity does not change the
> chart.

---

## Receivables

Shows, as of a given day (**"As of"**), which partners have outstanding balances, broken
down into ageing bands: **Not due**, **0-30 days**, **31-60 days**, **61-90 days**, and
**90+ days**. Per-partner and total band KPI cards, plus a per-partner table. Filterable by
a single partner.

---

## VAT Summary

A view intended for your accountant: here the **table is the primary element** (broken down
by period and VAT category, with per-category and grand-total rows), while the chart is only
**secondary, smaller, and shown below the table** — and only when more than one VAT category
is involved in the period.

---

## Export

All four tabs offer an **Export to CSV** button (if you have permission), which downloads
the data matching the tab's currently active filters as a CSV file.
