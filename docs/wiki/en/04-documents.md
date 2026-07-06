# 4. Documents

The sidebar's **Documents** menu item displays all invoices, receipts, and cancellations
in a single list — no need to switch between separate menus for invoices and receipts.
Back: [README.md](../README.md)

---

## List Layout

Table columns: document number · type · partner · issue date · gross total · currency ·
status · payment status.

Click a document number to open its detail page.

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

**Date filter:** "Date from" and "Date to" fields narrow the list by issue date. The ✕
next to the date inputs clears the date range.

**Items per page** (per-page selector): 10, 20, 50, or 100.

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

The **Regenerate PDF** button is permission-gated (requires the `invoice.regenerate_pdf` /
`receipt.regenerate_pdf` permission). After a confirmation prompt:

- The previous PDF file is archived with a timestamp — it is not deleted.
- A new PDF is generated using the current template and data.
- The event is recorded in the audit log.

This is typically used when the company logo or data has changed and an updated PDF of an
existing document is needed.
