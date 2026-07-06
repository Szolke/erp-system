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
