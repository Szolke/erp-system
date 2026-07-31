# 8. Other: Language Switching, Dark Mode, Audit Log

Back: [README.md](../README.md)

---

## Language Switching

The user interface is available in three languages: **Hungarian (HU)**, **English (EN)**,
and **German (DE)**.

Switch the language using the three buttons (**HU · EN · DE**) at the **bottom of the
sidebar**. The selected language takes effect immediately — all labels, button texts, and
error messages switch to the new language. The setting is tied to the user account and
persists after the next login.

---

## Dark / Light Mode

At the bottom of the sidebar, to the right of the language buttons, there is a
**Moon (🌙) / Sun (☀️)** icon. Clicking it toggles the interface between dark and light
themes.

- The choice is stored in the browser (persists across sessions).
- If no preference has been set, the system follows the operating system's theme setting.

---

## Audit Log (Settings → Audit Log)

The audit log records the history of significant actions performed in the system. The log
is only available to users who may view the audit log.

### What Does an Entry Contain?

Each row shows:

- **Timestamp** — when the event occurred
- **User** — who performed the action
- **Event** — the name of the action (e.g. `invoice.cancel`, `auth.login`,
  `company.manage`)
- **Record** — which database object was affected (e.g. `Invoice#42`)
- **Before / After values** — what changed (only present when data was modified)

These columns can be customised too: the **Columns** button above the list — the same
way as on the Documents page — lets you show/hide and reorder the individual columns.

### Filtering

Type an event name into the search box above the log (e.g. entering `invoice` shows only
invoice-related events). Click the **Search** button to refresh the list.

### What is the Audit Log For?

The audit log helps track:

- who issued or cancelled a document,
- when company data was changed,
- who logged into the system,
- PDF regeneration history.

Entries cannot be deleted; the system records them automatically at the end of the
relevant operation.

### "Created by / Updated by" Footer on Master Data Pages

Alongside the full audit log, master data pages show a discreet footer with the same
information at record level: **Created by** and **Updated by** — who the record is
attributable to, and when. This answers the most common question ("who touched this
last?") without opening the audit log.

The footer appears on these pages:

- Company Settings (below the company data form — it refers to the company record, which
  is why it is not at the very bottom of the page, where other sections follow)
- Partner, Product and Asset detail pages
- Sales group detail page
- User and Group detail pages

What it can show:

- **Name · timestamp** — this user created or last updated the record.
- **System** — the row was not written by a user but by the system (e.g. initial data
  seeding or a background process); no timestamp is shown in this case.
- **—** — the user behind the operation no longer exists in the system; the timestamp is
  still shown.

For a new, unsaved record the footer is not displayed at all.
