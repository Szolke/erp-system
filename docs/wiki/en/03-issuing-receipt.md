# 3. Issuing a Receipt

Start from the document list (Documents → **New receipt** button).
Back: [README.md](../README.md)

Issuing a receipt follows a similar process to an invoice, but with several important
differences — these are highlighted below. The general logic (line items, confirmation
modal, document number) is the same as described in [Chapter 2](02-issuing-invoice.md).

---

## Differences from an Invoice

| Aspect | Invoice | Receipt |
|---|---|---|
| Partner | Required | Optional — can be left blank (anonymous) |
| Currency | HUF or EUR | HUF, EUR, or USD |
| Exchange rate | No separate field | Required when EUR or USD is selected |
| Discount | Entered per line item (%) | Not available |
| Notes | Available | Not available |
| NAV Online Invoice | Yes (if enabled) | No |
| Payment status | Open → partial → paid | Settled immediately upon issuance |
| Fulfillment date | Required | Optional |

---

## Partner (Anonymous Receipt)

Leave the partner field blank when the buyer's name is not required. Such a document
appears in the list with a "—" partner, and the confirmation dialog and PDF will show
"Anonymous" instead of a partner name.

---

## Fulfillment Date

This field is optional. If left blank, the system automatically uses the issue date as
the fulfillment date.

---

## Currency and Exchange Rate

If you select EUR or USD, an **Exchange rate** input field appears — enter the applicable
mid-rate here (e.g. 410.5). The system fetches the daily MNB rate automatically, but the
rate must be entered manually when issuing the receipt.

---

## Payment Model

A receipt is considered **fully paid at the moment of issuance**. There is no separate
payment-entry section, and partial payments cannot be tracked (this is the intended
behaviour — a receipt represents a single point-of-sale transaction).

If open or partially-paid receipts become necessary in the future, that will require a
separate development effort.
