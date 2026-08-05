# 4. Documents

The sidebar's **Documents** menu item displays all invoices, receipts, and cancellations
in a single list — no need to switch between separate menus for invoices and receipts.
Back: [README.md](../README.md)

---

## List Layout

By default the table shows the most commonly used columns, but more columns are
available. Click the **Columns** button above the list to open the column picker:
individual columns can be shown or hidden with a checkbox, and non-locked columns can be
reordered by dragging the handle at the start of their row. Certain identifier columns
(e.g. the document number) always remain visible. The setting is saved separately per
user and per company — switching companies shows that company's own column preference.

Click a document number to open its detail page.

### Sorting with the Column Headers

The header of a sortable column is a clickable button with a small arrow icon next to its
label. Clicking the header cycles the sorting through **three states**:

1. **First click** — the list is sorted by that column.
2. **Second click** — the same column, in the opposite direction.
3. **Third click** — back to the list's default sorting (on Documents, issue date
   descending).

The direction of the first click depends on the column type: text columns start ascending
(alphabetical), while date, amount, and count columns start descending — so the most recent
or the largest value comes first. The icon shows the current state: an up arrow means
ascending, a down arrow descending, and a double (up-down) arrow means this column is not
being sorted on.

Only one column can be sorted at a time: clicking another header drops the previous sorting.
**Not every column is sortable** — where the content does not come from a single database
field (e.g. the Groups column on the user list, or the Before/After values in the audit log),
the header stays plain text and is not clickable.

Sorting applies to the whole result set, not just the page you are looking at, which is why
changing it returns the list to page one. Your chosen sorting is remembered per user and per
company — just like the columns and the page size — so it still applies the next time you
open the list. Sorting is, however, **not part of a shareable link**: if you send someone the
list's address, they see their own saved sorting. The column picker's **Reset to default**
button also resets the sorting.

---

## Filters and Search

**Type filter (pill buttons above the list):**

- All
- Invoice
- Cancellation invoice (storno)
- Receipt
- Cancellation receipt (storno)

Only the type buttons for which the user has the required permission are shown.

**Currency and payment status filters:**

- Currency: HUF / EUR / USD
- Payment status: Open / Partial / Paid — this filter is only shown when invoices (or
  cancellation invoices) are selected; it does not appear for receipts.

**Search:** text entered in the search box filters by document number and partner name.
The ✕ button on the right of the search field clears the text and resets the list.

**Date filter:** the **Date range** button (with a calendar icon) in the filter row opens a
calendar panel that narrows the list by issue date. The panel shows two months side by side:
the first click marks the start of the range, the second the end (if the second date is
earlier, the two are swapped). The selection only takes effect when you press **Apply** —
clicking around the calendar does not filter the list on its own, and **Cancel** discards the
selection in progress. Once a range is set, the button's label shows it instead of the "Date
range" placeholder.

The **Clear filter** button at the bottom left of the panel removes the date range condition;
it stays disabled while no range is set. (The status and currency filters, by contrast, are
removed from the chip row above the list using the ✕ button — the date range is deliberately
not shown as a chip.)

**Items per page** (per-page selector): 20, 50, 100, 200, or 500. The selected page size is
remembered per user and per company, so it still applies the next time you open the list.
If you open the list from a shared link that carries a page size, that value applies to
this visit only — it does not overwrite your own saved setting.

---

## Pagination

If the total number of results does not fit on one page, a paginator appears below the
list (previous/next, page numbers, ellipsis for long ranges). Changing any filter resets
the list to page 1.

---

## Document Details

Clicking a document number opens the detail page, where you can see the header
information, line items, totals, and — for invoices — the payment history.

---

## Cancellation

If the **Cancel** button is visible on a document's detail page (issued status, not
already cancelled), clicking it shows a confirmation prompt. After confirmation:

- The system creates a **cancellation document** (storno) with negative line-item amounts,
  and the original document's status becomes `cancelled`.
- The cancellation document and the original document reference each other.
- A document can only be cancelled once.

> For invoices paid via SimplePay, the **Refund** button replaces the Cancel button.
> Details: [Chapter 5](05-payments.md).

---

## Downloading a PDF

The **PDF** button on the detail page downloads the document as a PDF file. The filename
is the document number (e.g. `SZ-202407-000003.pdf`). If the PDF was already saved on the
server when the document was issued, that file is returned; otherwise the system generates
it on the fly.

---

## Regenerating a PDF

The **Regenerate PDF** button is permission-gated: it requires permission to regenerate the
PDF of an issued invoice or receipt — the two are separate permissions. After a
confirmation prompt:

- The previous PDF file is archived with a timestamp — it is not deleted.
- A new PDF is generated using the current template and data.
- The event is recorded in the audit log.

This is typically used when the company logo or data has changed and an updated PDF of an
existing document is needed.
