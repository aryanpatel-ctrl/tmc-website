# Quick Reference: Super Admin (TMC IT)

**Scope:** all six websites · **Access:** TMC network/VPN + MFA · **Full manual:**
[CMS Administrator Manual](../cms-administrator-manual.md)

## Where things are

*My Sites → Network Admin* → **Sites** · **Users** · **Settings** · **Audit Log**

## Users

| Task | Where |
|---|---|
| Create an account | *Network Admin → Users → Add New* |
| Give a role on a site | *Network Admin → Sites → (site) → Edit → Users → Add Existing User* |
| Leaver: delete from the network (attribute content) | *Network Admin → Users → Delete* |
| Grant / revoke Super Admin | *Network Admin → Users → Edit* (checkbox) — keep to 2 named officers |

Record every grant in the access register ([Access Control Policy §7](../../architecture/access-control-policy.md#7-access-register)).

## Audit log

| Task | How |
|---|---|
| Find events | Filters: site, event, username → **Filter** |
| Check integrity | **Verify integrity** → "All N entries are intact" + latest hash |
| Tampering detected | **P1 incident**: change nothing, export, call the Vendor Support Lead and TMC CISO |
| Export | **Export CSV** (uses the current filters; the export is itself logged) |

Monthly: review `super_admin_*`, `user_role_changed`, `plugin_*`, `theme_switched`, `site_*`,
`network_setting_changed`, bursts of `login_failed`.
Quarterly: **Verify integrity**, record count + full latest hash, archive a full CSV export.

## Adding a unit website

Change Request → pull request adding the site to the provisioning lists → CI → UAT → Production
([procedure](../cms-administrator-manual.md#8-adding-a-new-unit-website)). *Sites → Add New* alone is not
enough.

## Never

- Install or activate plugins/themes from the admin in Production (releases only).
- Share accounts or send passwords by e-mail/chat.
- Delete audit-log records (it is designed to detect that).
