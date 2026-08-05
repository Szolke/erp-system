# 10. NAV eNyugta (Receipt Data Reporting)

> **This entire module is only available in Hungarian**, regardless of your interface
> language setting — the reports list, the report detail page, and the settings page all
> show Hungarian labels, buttons, and messages, the same way the Munkakörök page does (see
> [Chapter 6](06-settings.md)). The description below explains what each screen does; the
> on-screen text itself will still read in Hungarian.

The sidebar's **eNyugta jelentések** item and Settings → **NAV eNyugta** support fulfilling
the NAV receipt-data-reporting obligation: a daily summary of receipts issued by computer
must be reported to the Hungarian tax authority. The module can be enabled or disabled per
company, and is tied to the receipts feature — if your company doesn't issue receipts, you
don't need it. (The reporting obligation only applies to businesses that actually issue
receipts.)
Back: [README.md](../README.md)

---

## Automatic Daily Report Generation

Daily reports are NOT created manually on screen — a background process generates them
automatically, every day overnight, for the previous day, for every company that has the
module enabled. The user's role is limited to viewing and exporting the reports that already
exist; a new report cannot (and does not need to) be triggered manually.

---

## The Reports List

The "NAV eNyugta — Jelentések" page is filterable by date range and status. The date range
is set in the calendar panel behind the **Date range** button, and the selection takes effect
when you press **Apply**. The **Clear filter** button at the bottom left of the panel removes
the date filtering again, if you want to see the full period.

The table columns (customisable the same way as the Documents list, via the Columns button):
day, type, status, receipt count, gross total. The list **can also be sorted**: clicking a
sortable column header cycles through the usual three states (ascending → descending →
default), the same way as on the Documents list ([Chapter 4](04-documents.md)).

> The status filter offers five options (Piszkozat / Kész / Beküldés alatt / Elfogadva /
> Elutasítva — Draft / Ready / Sending / Accepted / Rejected), but since actual NAV
> submission is not available today, reports stay in draft status — in practice, the other
> options never match anything.

If no receipts were issued in the last 30 days, an informational banner appears at the top
of the list.

---

## Report Details

Clicking a report opens the daily detail view: a breakdown by VAT category (net / VAT /
gross / receipt count per row), plus a daily total.

**CSV export** — this is the module's working path today: the report can be downloaded as a
CSV file, which the user then uploads manually to the NAV KOBAK portal. This is a complete,
functional way to fulfil the reporting obligation while automatic (machine) submission is
unavailable.

---

## Settings

The Settings → NAV eNyugta page lets you configure:

- **Tax number** (8-digit)
- **Mode** — a dropdown with three Hungarian-only options: "Teszt (mock, NAV-kapcsolat
  nélkül)" (test, no NAV connection), "NAV teszt" (NAV test environment), "Éles"
  (production)
- **Base URL override** (optional)
- **"Also submit reports for zero-receipt days"** toggle — this toggle currently has no
  practical effect, because actual submission isn't available at all; it will only become
  relevant once NAV submission goes live.
- **Technical user** — login name, password, signing key, exchange key. These fields are
  stored encrypted and are never shown back on screen — you only see whether each one is
  "set" or "not set". Leaving a field blank when saving keeps its previous value.

**Copy from Online Invoice settings** — a button that, after confirmation, copies the NAV
Online Invoice technical user's credentials into this page. The confirmation dialog warns
that this overwrites the values set above, and that it is not guaranteed the same technical
user actually works on the eNyugta interface too.

---

## Current Limitations of NAV Submission

Actual, automatic NAV submission does not work today — this is **not a development gap, but
a dependency on NAV**: NAV has not yet published a base URL for receipt-data reporting, for
either the test or the production environment. Until that happens, submission is not
technically possible — regardless of whether "Éles" (production) is selected in the Mode
field. The CSV export and manual entry on the NAV KOBAK portal, described above, is how the
obligation can be fulfilled today.

---

## Permissions

Viewing reports and editing the settings require separate permissions — if you only have
viewing rights, the fields on the Settings page cannot be edited.
