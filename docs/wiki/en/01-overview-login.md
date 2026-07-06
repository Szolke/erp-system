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
  the header (details below), followed by the menu items (Documents, Partners, Products, and
  the Settings submenu). At the bottom of the sidebar you will find the language switcher,
  the dark/light mode toggle, the username, and the **Log out** button.
- **Main content** — the larger area on the right; this is where the current page is
  displayed (lists, details, forms).

The sidebar is collapsible: clicking the arrow button in the header shrinks it to icon-only
mode (54 px wide), giving more room to the content area. The state persists across sessions.

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

---

## Language Switcher and Dark Mode

At the bottom of the sidebar:

- **Language switcher** — toggle between HU / EN / DE; all labels on the screen update
  immediately. Details: [Chapter 8](08-other.md).
- **Dark mode toggle** (Moon/Sun icon) — switch between dark and light themes. The choice
  is stored in the browser. Details: [Chapter 8](08-other.md).
