# 5. Payments

Payments are managed on the **invoice detail page** (Documents → open an invoice).
Back: [README.md](../README.md)

> **Receipts have no payment section**: a receipt is considered fully paid at the moment
> of issuance. All payment features described here apply to invoices only.

---

## Manual Payment Entry

The invoice detail page shows a **Payments** section below the line items. If the invoice
is not yet fully paid, a payment entry form appears at the bottom of the section.

Form fields:

- **Amount** — the amount paid, in the invoice's currency.
- **Payment method** — select from the configured payment methods.
- **Date** — defaults to today; can be changed if needed.

Multiple partial payments can be recorded for a single invoice.

---

## The "Open Balance" Button

Next to the amount field, a button labelled **"Open balance: X"** appears when there is
still an outstanding amount. Clicking it automatically fills the amount field with the
remaining balance (rounded to a whole number for HUF; 2 decimal places for other
currencies).

---

## Payment Status

The invoice's payment status updates automatically based on the payments recorded:

- **Open** — no payment has been recorded yet
- **Partial** — some payment has been recorded, but the full amount has not been settled
- **Paid** — the full gross amount has been recorded

Once the invoice is fully paid, the amount field and the submit button disappear.

---

## SimplePay Online Payment

If the company's SimplePay integration is enabled and configured, an online payment flow
can be initiated from the invoice detail page. A **SimplePay** button appears — the
payment process takes place on the OTP SimplePay website and then redirects back to the
system.

A successful SimplePay transaction is automatically recorded as a payment.

---

## SimplePay Refund and Cancellation

If an invoice was paid via SimplePay and the transaction was successful, the **Refund**
button replaces the **Cancel** button.

Clicking the Refund button (after confirmation) causes the system to:

1. Initiate the refund via the SimplePay API.
2. Mark the transaction status as `refunded`.
3. Automatically create the cancellation invoice (storno).

Both steps happen together — no separate cancellation is needed.

> **Note:** the SimplePay refund feature requires sandbox testing with real merchant
> credentials before going live. Verify the SimplePay confirmations in production.
