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

The audit log records the history of significant actions performed in the system.
Viewing the log requires the `audit.view` permission.

### What Does an Entry Contain?

Each row shows:

- **Timestamp** — when the event occurred
- **User** — who performed the action
- **Event** — the name of the action (e.g. `invoice.cancel`, `auth.login`,
  `company.update`)
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
