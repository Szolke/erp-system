# 1. Overview and Login

This guide consists of eight chapters. For the full list of topics, see the
[README.md](../README.md).

---

## What is this system?

This ERP (Enterprise Resource Planning) system supports invoice and receipt management,
partner and product records, payment tracking, and NAV Online Invoice reporting for small
and medium-sized businesses.

Main areas of the system:

- **Invoicing and receipts** — issue invoices and receipts, cancel them, download PDFs
- **Master data** — maintain records for partners and products/services
- **Payments** — manual payment entry and SimplePay online payment integration
- **NAV Online Invoice** — issued invoices are automatically reported to the Hungarian tax
  authority (if enabled in company settings)
- **Multiple companies** — a single user can manage multiple companies if assigned to them

---

## Logging In

The system runs in the browser. Open the frontend URL and enter your email address and
password on the login screen.

**For the local test environment** (developer demo):
- Email: `test@example.com`
- Password: `password`

After a successful login, the system opens the last active company context.

---

## Interface Layout

After logging in, you will see two main areas:

- **Sidebar** — on the left; the primary navigation tool. The company switcher is shown in
  the header (details below), followed by the menu items. The main items are: **Dashboard**,
  Documents, Reports, Partners, Products, Assets, *eNyugta jelentések* (the NAV eNyugta
  reports screen — shown under this Hungarian name regardless of interface language) and
  **User guide** (this document — available from within the app, no need to leave it).
  Below these, in a separate block, the **Settings** submenu, organised into categories
  (details: [Chapter 6](06-settings.md)). At the bottom of the sidebar you will find the
  language switcher, the dark/light mode toggle, the username, and the **Log out** button.
- **Main content** — the larger area on the right; this is where the current page is
  displayed (lists, details, forms).

> Which menu items you see depends on your permissions and on which modules are enabled —
> not every user sees the full list above.

The sidebar is collapsible: clicking the arrow button in the header shrinks it to icon-only
mode, giving more room to the content area. The state persists across sessions.

---

## Dashboard

After a successful login, the first screen is the Dashboard (the page heading reads
"Overview"), giving a quick snapshot of the company's current status:

- **Unpaid** — the total amount of all invoices not yet paid, in the primary currency,
  together with the item count; if the company invoices in more than one currency, the
  other currencies' totals are shown on a separate line.
- **Overdue** — the total for invoices past their due date, highlighting how many days ago
  the oldest one became overdue.
- **This month's invoicing** — the gross total of invoices issued in the current month.
- **NAV status** — the error count from NAV Online Invoice submissions and the timestamp of
  the last sync; only shown if NAV integration is enabled for the company.
- **Oldest unpaid invoices** table — the longest-open items with invoice number (a
  clickable link to the invoice), partner name, and days overdue/remaining.

> Which cards and the table appear also depends on permissions and on the NAV module's
> status — not every user sees all of them.

---

---

## Company Switcher

If your account is assigned to more than one company, a drop-down menu showing the company
names appears in the sidebar header. Clicking a name switches you to that company — all
lists, documents, and settings immediately reflect the new company.

If you belong to only one company, the company name is shown as plain text instead of a
drop-down.

> **Creating companies** — superadmin users can create new companies and assign users to
> them via the Settings → **Companies** menu item. This menu item is not visible to regular
> users.
>
> **Cross-company visibility** — the company switcher decides which company's data the lists,
> forms and settings show; for regular users there is no exception to this. Superadmins have a
> single screen that reaches across companies without switching: Settings → **Groups — all
> companies** (see [Chapter 6](06-settings.md)).

---

## Language Switcher and Dark Mode

At the bottom of the sidebar:

- **Language switcher** — toggle between HU / EN / DE; all labels on the screen update
  immediately. Details: [Chapter 8](08-other.md).
- **Dark mode toggle** (Moon/Sun icon) — switch between dark and light themes. The choice
  is stored in the browser. Details: [Chapter 8](08-other.md).
