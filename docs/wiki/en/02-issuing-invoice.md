# 2. Issuing an Invoice

Start the process from the document list (Documents → **New invoice** button).
Back: [README.md](../README.md)

---

## Form Fields

**Header information:**

- **Partner** — required; select from your existing partner records. (Partners are added
  in the Partners section before you can invoice them.)
- **Payment method** — required; the first available method is pre-selected automatically.
- **Issue date** — defaults to today.
- **Fulfillment date** — required; defaults to today.
- **Due date** — required; defaults to 30 days from today.
- **Currency** — HUF or EUR.
- **Notes** — optional free-text field; appears on the PDF.

---

## Line Items

Each row represents one product or service. At least one row is required, and every row
must have a product selected.

Fields within a row:

- **Product / description** — use the product combo box to select a product; it
  automatically fills in the unit price, VAT rate, and unit of measure. After selection,
  the description and unit price remain editable.
- **Unit** — free-text field (e.g. pcs, hour, kg).
- **Quantity** — numeric, up to 3 decimal places.
- **Unit price** — net unit price.
- **VAT** — the rate configured for the product is pre-selected; can be changed.
- **Discount %** — optional; if entered, it is deducted from the line total.

Add rows with the **+ Add line item** button. Remove a row with the × button on the right
(the last remaining row cannot be removed).

---

## Confirmation Modal

Clicking the **Preview / Issue** button opens a summary dialog showing the header
information and all line items with an estimated total. The invoice is **not yet saved**
at this point.

Click **Issue** in the summary to submit the data. Click **Cancel** to go back to the
form and make corrections.

---

## The Document Number

> **Important:** the invoice's serial number is assigned by the server at the moment of
> issuance. For this reason the number is not shown in the summary dialog — it only
> appears after successful issuance, in the green confirmation message
> (e.g. `SZ-202407-000003`).

This is a requirement of gapless sequential numbering: two invoices issued simultaneously
can never receive the same number.

---

## After Successful Issuance

The confirmation dialog shows two options:

- **View document** — opens the new invoice's detail page (where you can record payments,
  download the PDF, etc.).
- **Back to list** — returns to the document list.

The issued invoice immediately appears with **issued** status. If the NAV Online Invoice
integration is enabled, the report is submitted automatically in the background.
