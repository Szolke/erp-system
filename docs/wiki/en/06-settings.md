# 6. Settings

The Settings submenu contains several pages organised into categories: **Company data**,
**General**, **Users**, **Dictionaries**. Pages are gated by permissions — some are
restricted to superadmins only; if a page is not visible in the sidebar, you do not have
access to it.
Back: [README.md](../README.md)

---

## Company data

> The **Companies** menu item (creating a new company, superadmins only) is covered in
> [Chapter 1](01-overview-login.md), in the Company Switcher section.

### Company Settings (Settings → Company Settings)

The page is organised into several sections.

#### Company Data

Basic company information: name, tax number, EU tax number, registration number, postal
code, city, address, email, phone, base currency, NAV Online Invoice environment
(test/production), invoice header text, and invoice footer text. The **Save** button saves
all fields at once.

The NAV environment (test / production) field is only visible to users with the
permission to manage company data.

#### Company Logo Upload

The company logo appears on issued PDFs. Requirements: JPEG, PNG, GIF, or WebP format,
maximum 2 MB. After upload, the logo is immediately shown in the preview area. The
**Delete** button removes the logo (previously archived PDFs are not affected).

This section is only visible to users who may manage company data.

#### General Settings

- **Default currency** — the currency configured for the company (HUF / EUR / USD).
  Invoices support HUF and EUR at issuance; receipts support all three (HUF, EUR, USD).
- **Invoice language** — the language used for labels on generated PDFs (Hungarian /
  English / German).
- **Payment due days** — new invoices are given a due date this many days after the
  issue date by default.

This section is only visible to users who may manage company data.

#### Sidebar Accent Colour

Choose from 18 predefined colours for the sidebar background. The selected colour takes
effect immediately and is stored per company — different companies can have different
colours.

This section is only visible to users who may manage company data.

#### SimplePay Credentials

Online payment integration configured per currency (HUF / EUR / USD). Each currency has
its own merchant ID and secret key. The secret key is stored encrypted and is never
displayed — only a "set / not set" indicator is shown. The **Sandbox** toggle keeps the
payment flow in test mode.

This section is only visible to users who may manage SimplePay credentials, and only when
the SimplePay module is enabled.

#### Sales Group Prefix

A separate section on the page with its own **Save** button — it is not part of the
Company Data form, so saving that form does not affect it. It only appears when the
Sales Groups module is enabled.

The prefix is up to 4 uppercase letters (e.g. `BUD`) — the system normalises it to
uppercase automatically. It forms the first part of every sales group's display name:
`PREFIX_GroupName`. The prefix cannot be cleared while the company still has sales
groups; those must be removed first.

#### NAV Online Invoice Credentials

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

This section is only visible to users who may submit invoices to NAV, and only when the
NAV Online Invoice module is enabled.

### Document Series (Settings → Document Series)

Configure the prefix and format for document serial numbers here. The default series
(`SZ`, `NY`, `SZSZT`, `NYSZT`) are created automatically when a new company is set up.

Serial number format: `PREFIX-YYYYMM-000001` (e.g. `SZ-202407-000001`). Numbering resets
automatically at the start of each year.

Requires permission to manage document serial number ranges.

---

## General

### Module Manager (Settings → Modules)

This page is only accessible to **superadmins**.

The system is modular: certain features (NAV Online Invoice, SimplePay online payments,
Sales Groups) can be enabled or disabled per company. Core modules (marked **Always
active**) — invoicing, receipts, partners, products — cannot be toggled and are always on.

**Enabling / disabling a module:**
Each module card shows a toggle. If a module has a dependency (e.g. NAV Online Invoice
requires the invoicing module), the system checks that the dependency is active — if not,
the toggle fails with an error message naming the missing module. Conversely, if
an active module is depended on by another active module, the former cannot be disabled
until the dependent module is disabled first.

**"Configure →" link:**
For enabled modules that have a settings page (e.g. NAV Online Invoice, SimplePay,
Sales Groups), a "Configure →" link appears at the bottom of the card. It navigates to
the module's own settings page or to the relevant section of the Company Settings page.

### NAV Log (Settings → General → NAV Log)

Every NAV submission attempt is logged — both the initial invoice send and any later
verdict check. This list shows whether anything is wrong: by default it's filtered to
invoices that are failed, rejected, or stuck without a verdict after 24 hours (needs
attention). One row represents one affected invoice, together with its attempt count —
not one row per individual attempt.

Each row can be expanded to show the latest attempt's details, including the raw data
sent to and received from NAV (loaded only on demand, not as part of the list). The
invoice's own detail page also shows a "NAV submission history" panel with that invoice's
full chronological history.

The log can also be **narrowed to a date range**: the **Date range** button above the list
opens a calendar panel, the selection takes effect when you press **Apply**, and the **Clear
filter** button at the bottom left of the panel removes the date filtering again. The columns
can be customised with the **Columns** button the same way as on the Documents page, and the
list can be sorted by clicking a sortable column header ([Chapter 4](04-documents.md)) — with
the exception of the **Attempts** column, whose value the system computes afterwards.

This list/panel is only visible to users who may view the NAV log — a SEPARATE permission
from submitting invoices to NAV, because the log contains raw NAV communication and is
more sensitive than viewing the invoice itself.

### Custom Fields (Settings → Custom Fields)

Add custom data fields to partners and products. Available types: text, number, date,
yes/no (boolean), pick list (select). For the select type, enter each possible value on
a separate line.

Managing custom fields requires permission to manage company data. Defined fields appear on the
partner and product forms, and the data entered there is stored with the respective
partner or product record.

### API Tester (Settings → API Tester)

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

Requires permission to use the built-in API tester.

---

## Users

The **Users** and **Groups** pages are available here; for the full description of the
permission system (RBAC) — roles, group membership, per-user overrides — see
[Chapter 7](07-users-permissions.md).

---

## Dictionaries

### Countries (Settings → Countries)

This page is only available to **superadmins**. It controls which countries appear as
selectable options system-wide — the list is controlled with checkboxes, can be filtered
by search, and is saved with a single **Save** button.

> Countries disabled here are also unavailable in the partner form's "Country" field —
> the two lists are linked. If a partner's already-saved country is later disabled, it
> does not disappear from that partner's record; it just can't be selected again as a
> new value.

### Munkakörök (Job Positions) (Settings → Munkakörök)

> **This entire screen is only available in Hungarian**, regardless of your interface
> language setting — not just the menu label (as with the *eNyugta jelentések* item in
> [Chapter 1](01-overview-login.md)), but the page title, buttons, and every field label
> too. The description below explains what the screen does; the on-screen text itself
> will still read in Hungarian.

Freely named job positions (e.g. "Recepciós" – receptionist, "Könyvelő" – bookkeeper) can
be created and assigned to users — the field is purely informational and is not tied to
any permission. A job position can be marked inactive: existing assignments are unaffected,
it just becomes unavailable for new selections. Superadmins can also create a "global" job
position, which applies to every company, not just their own.

The "Munkakör" field on the user form (when creating a user, and on the user's detail page)
is where a job position is selected from the list.

### Asset Types (Settings → Asset Types)

This page is only available when the **Assets** module is enabled, and permission to view
assets is required to open it.

The page shows both the global types (available to every company — e.g. Mobile phone, Teya
POS terminal, Printer) and the types your own company has created; the **Scope** column
indicates which is which. **Adding a new type** (with permission to create assets): you
provide a code (e.g. `LAPTOP` — the system normalises it to uppercase) and a name (e.g.
`Laptop`). The code must be unique within the company. A type added this way always
belongs to your own company — other companies cannot see it.

Types cannot be edited or deleted afterwards: the type's code is built into the names of
the assets recorded against it.

The register of the assets themselves — list, adding, editing, statuses, deletion — lives
under the top-level **Assets** menu item, see [Chapter 11](11-assets.md).

### Sales Groups (Settings → Sales Groups)

This page is only available when the **Sales Groups** module is enabled and requires
permission to view sales groups.

Sales groups are scoped to a company: this page always shows the groups of the currently
selected company, and groups belonging to other companies cannot be reached here — neither
for viewing nor for editing. (The only exception is the superadmin cross-company overview,
see below.) Each group's display name is composed of the company prefix and the group
name: `PREFIX_GroupName` (e.g. `BUD_North`).

**Adding a group** (with permission to create sales groups):
The name must be unique within the company (case-insensitive). If no prefix has been
configured for the company, the system shows an error prompting you to set a prefix in
Company Settings first.

**Editing** (with permission to edit sales groups): only the group name can be changed;
the display name updates automatically.

**Deleting** (with permission to delete sales groups): the group is permanently removed.

#### Managing Group Members

The **Members** section on a group's detail page shows who belongs to the group; the
heading also displays how many members are currently selected. **Membership is saved
separately from the group name** — the section has its own **Save membership** button,
which is only enabled while there are unsaved changes (an "unsaved changes" note appears
alongside it).

The section has two blocks:

- **Selected members** — a fixed list of the currently selected users, always visible
  regardless of the search. The **×** button next to a name removes that person from the
  selection.
- **Search by name / email** — searches among the company's users; results can be added
  to the group with checkboxes. The search runs on the server and starts after a short
  delay while you type, so it stays usable in companies with many users. At most 50
  results are shown at a time; when that many come back, the interface tells you to
  narrow the search.

Editing members requires permission to edit sales groups **and** permission to list the
company's users. If either is missing, the section is read-only — the existing membership
is still shown, and the interface states which permission is missing.

#### Groups — All Companies (superadmin)

Superadmin users see a second menu item in the Settings submenu: **Groups — all companies**.
This is an overview screen listing every company's sales groups and their members, grouped by
company, without switching companies. The menu item is visible to superadmins only: this
permission cannot be handed to anyone else through group membership or a permission override.

As a superadmin you can create, rename and delete groups **in any company** from here. When
adding a group, the **Target company** drop-down determines which company it belongs to. Two
conditions are worth keeping in mind:

- the target company must have a sales group prefix configured — without it the save is
  rejected (the company selector also flags a missing prefix);
- the Sales Groups module must be enabled for the target company — the selector does not show
  this, so with the module disabled only the save reports the error.

**Membership cannot be edited on this page** — that still happens per company, on the Sales
Groups page above, always for the currently selected company. The overview only displays
members.

### Translations (Settings → Translations)

Interface texts (buttons, labels, error messages, etc.) can be edited in this editor
across three languages (HU / EN / DE). Changes take effect immediately on the user
interface.

Requires permission to manage company data.
