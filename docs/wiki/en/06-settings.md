# 6. Settings

The Settings submenu contains several pages accessible from the Settings section in the
sidebar. Each page is gated by a permission such as `company.manage` — if a page is not
visible in the sidebar, you do not have access to it.
Back: [README.md](../README.md)

---

## Company Settings (Settings → Company Settings)

This page has four main sections.

### Company Data

Basic company information: name, tax number, EU tax number, registration number, postal
code, city, address, email, phone, base currency, NAV Online Invoice environment
(test/production), invoice header text, and invoice footer text. The **Save** button saves
all fields at once.

The NAV environment (test / production) field is only visible to users with the
`company.manage` permission.

**Sales group prefix:** when the Sales Groups module is enabled, a **Sales Group Prefix**
field also appears here. The prefix is up to 4 uppercase letters (e.g. `BUD`) — the
system normalises it to uppercase automatically. It forms the first part of every sales
group's display name: `PREFIX_GroupName`. The prefix cannot be cleared while the company
still has sales groups; those must be removed first.

### Company Logo Upload

The company logo appears on issued PDFs. Requirements: JPEG, PNG, GIF, or WebP format,
maximum 2 MB. After upload, the logo is immediately shown in the preview area. The
**Delete** button removes the logo (previously archived PDFs are not affected).

This section is only visible to users with the `company.manage` permission.

### General Settings

- **Default currency** — the currency configured for the company (HUF / EUR / USD).
  Invoices support HUF and EUR at issuance; receipts support all three (HUF, EUR, USD).
- **Invoice language** — the language used for labels on generated PDFs (Hungarian /
  English / German).
- **Payment due days** — new invoices are given a due date this many days after the
  issue date by default.

This section is only visible to users with the `company.manage` permission.

### Sidebar Accent Colour

Choose from 18 predefined colours for the sidebar background. The selected colour takes
effect immediately and is stored per company — different companies can have different
colours.

This section is only visible to users with the `company.manage` permission.

### SimplePay Credentials

Online payment integration configured per currency (HUF / EUR / USD). Each currency has
its own merchant ID and secret key. The secret key is stored encrypted and is never
displayed — only a "set / not set" indicator is shown. The **Sandbox** toggle keeps the
payment flow in test mode.

This section is only visible to users with the `simplepay.manage` permission and only when
the SimplePay module is enabled.

### NAV Online Invoice Credentials

Configure the technical user credentials required for the NAV Online Invoice 3.0 API. Two
environments (test / production) are managed independently with separate credential sets.

The active environment (set under **NAV Online Invoice environment** in the Company Data
section) determines which credentials the system uses for automatic invoice reporting.

**Warning banner:** if the NAV module is enabled but no `is_active=true` credential exists
for the active environment, a yellow warning banner is displayed — in this state the NAV
sending job skips reporting and writes a warning to the log.

**Credentials per environment:**
- Technical username
- Technical user password
- XML signing key
- XML exchange key

All secret fields are stored encrypted and are never returned to the UI — leaving a field
blank when saving keeps the existing value unchanged.

**Switching the active environment:** switching NAV environments (test → production) requires
confirmation via a modal and is only possible when a credential already exists for the target
environment.

This section is only visible to users with the `invoice.send_nav` permission and only when
the NAV Online Invoice module is enabled.

---

## Document Series (Settings → Document Series)

Configure the prefix and format for document serial numbers here. The default series
(`SZ`, `NY`, `SZSZT`, `NYSZT`) are created automatically when a new company is set up.

Serial number format: `PREFIX-YYYYMM-000001` (e.g. `SZ-202407-000001`). Numbering resets
automatically at the start of each year.

Requires the `document_series.manage` permission.

---

## Custom Fields (Settings → Custom Fields)

Add custom data fields to partners and products. Available types: text, number, date,
yes/no (boolean), pick list (select). For the select type, enter each possible value on
a separate line.

Custom fields require the `company.manage` permission. Defined fields appear on the
partner and product forms, and the data entered there is stored with the respective
partner or product record.

---

## Translations (Settings → Translations)

Interface texts (buttons, labels, error messages, etc.) can be edited in this editor
across three languages (HU / EN / DE). Changes take effect immediately on the user
interface.

Requires the `company.manage` permission.

---

## Module Manager (Settings → Modules)

This page is only accessible to **superadmins**.

The system is modular: certain features (NAV Online Invoice, SimplePay online payments,
Sales Groups) can be enabled or disabled per company. Core modules (marked **Always
active**) — invoicing, receipts, partners, products — cannot be toggled and are always on.

**Enabling / disabling a module:**
Each module card shows a toggle. If a module has a dependency (e.g. NAV Online Invoice
requires the invoicing module), the system checks that the dependency is active — if not,
the toggle fails with a 422 error and shows the name of the missing module. Conversely, if
an active module is depended on by another active module, the former cannot be disabled
until the dependent module is disabled first.

**"Configure →" link:**
For enabled modules that have a settings page (e.g. NAV Online Invoice, SimplePay,
Sales Groups), a "Configure →" link appears at the bottom of the card. It navigates to
the module's own settings page or to the relevant section of the Company Settings page.

---

## Sales Groups (Settings → Sales Groups)

This page is only available when the **Sales Groups** module is enabled and requires the
`sales_group.view` permission.

Sales groups are scoped to the current company — groups belonging to other companies are
not visible. Each group's display name is composed of the company prefix and the group
name: `PREFIX_GroupName` (e.g. `BUD_North`).

**Adding a group** (`sales_group.create`):
The name must be unique within the company (case-insensitive). If no prefix has been
configured for the company, the system returns a 422 error prompting you to set a prefix
in Company Settings first.

**Editing** (`sales_group.edit`): only the group name can be changed; the display name
updates automatically.

**Deleting** (`sales_group.delete`): the group is permanently removed.

---

## Assets and Asset Types (Assets / Settings → Asset Types)

This feature is only available when the **Assets** module is enabled. The **Assets**
menu item (top of the sidebar) tracks the company's physical assets (e.g. POS
terminals, phones) — the `asset.view` permission is required to view it.

**Adding an asset** (`asset.create`): you must provide the serial number and asset
type; the IMEI is optional. **The name is generated by the system** from the type's
code and a sequence number (e.g. `DEMO_TEYA_00001`) — this field cannot be edited
when creating; it appears only after saving.

**Editing** (`asset.edit`): only the status (active / issued / in service /
scrapped), serial number, and IMEI can be changed. The name and asset type are
permanently fixed after creation.

**Deleting** (`asset.delete`): the asset is permanently removed.

The **Asset Types** page (Settings → Asset Types, `asset.view` permission) shows
both the global types (available to every company — e.g. Mobile phone, Teya POS
terminal, Printer) and the types your own company has created. **Adding a new
type** (`asset.create`) always creates it under your own company — other companies
cannot see it.

---

## API Tester (Settings → API Tester)

An interactive interface for browsing and testing the ERP's own REST API. The left panel
shows all available endpoints in collapsible groups (method badge + path); the right panel
displays the endpoint details: parameter table, request body schema, and the "Send request"
card.

**Sending a request:**
- Path parameters such as `{invoice}` or `{partner}` are filled in dedicated input fields.
- Query parameters (e.g. `page`, `per_page`) can also be provided.
- POST/PUT/PATCH methods show a JSON body textarea, pre-filled from the schema.
- After clicking **Send**, the response (HTTP status, duration, JSON body) appears at the
  bottom of the right panel.

**Protection for irreversible operations:**  
For cancel (`/cancel`), SimplePay refund (`/refund`), PDF regeneration (`/regenerate-pdf`),
and delete (DELETE) endpoints, a red-bordered warning modal appears instead of the standard
confirmation. It displays the active company name and requires ticking an "I understand —
this operation is irreversible" checkbox before the Send button becomes active.

Requires the `api_tester.use` permission.
