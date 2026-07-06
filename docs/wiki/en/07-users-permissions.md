# 7. Users and Permissions

The system uses role-based access control (RBAC). The administrative interfaces are in the
Settings submenu: **Users** and **Groups**.
Back: [README.md](../README.md)

---

## How the Permission System Works

Every action (e.g. issuing an invoice, viewing a partner, changing settings) is tied to a
**permission key**. A user can perform an action if:

1. They are a member of a group that holds that permission, **or**
2. That permission is individually **overridden** to "allow" on their account.

**Deny overrides allow**: if a group grants a permission but the user has a "deny" override
set for it, the permission is **not** effective.

---

## Groups (Settings → Groups)

A group is a named permission bundle. For example, a "Finance" group might hold
invoice/receipt/payment permissions, while a "Data Manager" group holds
partner/product/company/user/group permissions.

On a group's detail page:

- Check boxes select which permissions the group holds (organised by module).
- The group's member list can be viewed and managed.

---

## Users (Settings → Users)

The user list shows all active users with their group memberships.

### Managing Group Membership

The **Groups** section on a user's detail page shows which groups they belong to. A new
group can be added via a drop-down; the **Remove** button removes the membership.
Requires the `group.manage` permission.

### Per-User Permission Overrides

The **Permissions** section on a user's detail page lists every permission, organised by
module. The button on each permission row cycles through three states:

- **No override** — the group's decision applies (default).
- **Allow** (green) — the user receives this permission even if no group grants it.
- **Deny** (red) — the user cannot perform this action even if a group grants it.

Permissions inherited from a group are highlighted with a blue background, making it
clear which rows the overrides affect.

Permissions marked as **Sensitive** (e.g. PDF regeneration, cancellation) are displayed
separately.

Changes are saved with the **Save overrides** button. Requires the
`permission.override` permission.

---

## Superadmin Role

A superadmin user **automatically receives every permission** — no group assignment is
needed, and deny overrides do not apply. Superadmin users:

- Can see the **Companies** menu item (create companies, switch between them).
- Cannot be deleted or deactivated from the system.
- Are shown with a "Superadmin" badge in the user list.

For information on setting up the first superadmin account, see the deployment guide. In
the demo environment, `test@example.com` / `password` is the superadmin account.
