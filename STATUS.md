# STATUS: MSP licence control panel

**Read this first.** Any agent (Claude, Cline, another provider) should be able to continue from this file alone. It is
kept current during work, not only at the end. Last updated: **2026-11-11**.

## What this is
This is a private PHP 8.3 / MySQL panel that licenses and bills the owner's hotel PMS / HMS installs. Each HMS calls
`public/api/licence.php` and gets a signed answer back. That answer carries the status, the modules, the trial and the
staff changes.
- Live at the subdomain on cPanel. Pushing `main` to GitHub deploys it live.
- The database is shared with the Liwonde hotel for now. Only touch `cp_*` tables. Tenants 1 (Rosalyn's) and 2
  (Liwonde) are the live hotels.
- Reading order:
  1. `README.md` (setup and security)
  2. `BILLING.md` (billing design)
  3. `MOVING.md` / `EMERGENCY.md`
  4. In-app guides: `public/guide.php` and `public/guide-sales.php`

## Owner rules (always)
- **Commits and pushes:** commit or push only when the owner asks, because pushing `main` goes live. Stage files by name
  (never `-a` / `-A`). Never stash.
- **Migrations:** apply every DB migration yourself (`php scripts/migrate.php`). After each one:
  1. Run the `--db` tests.
  2. Refresh the offline copy with `php scripts/export-db.php` (writes `database/cp-export-*.sql`, git-ignored).
  3. Update `database/README.txt`.
- **Secrets:** never print anything from `.env`, SMTP settings or the exports.
- **Hotel repos:** their local `.env` files point at the **live** hotel databases. Run only pure tests there. Never run
  `scripts/tests/licence_client.php`: it writes `site_settings`.
- **This file:** keep it current (rule one).

## Live now (pushed)
Everything up to commit `5022fe1` is live:
- Billing: invoices, quotations, recurring, clients, reports, outbox, automations.
- Free trials: start, lengthen, shorten, end, convert, restart.
- Void and refund of payments.
- Prices follow the plan.
- Month ends stay month ends.
- Tidy form layout and tiled action boxes on the business page.
- HMS staff and permissions from the panel, and modules enforced in the hotels (commit `df258c0` plus the follow-up).
- The database has migrations up to **010** applied (009: the HMS staff tables; 010: the HMS staff-step error).

## In progress: HMS staff and modules from the panel (owner 2026-11-10)
**What the owner asked for:**
- Manage which modules each HMS may use.
- Manage the HMS's users and permissions from the panel, including deactivating users, wherever the HMS is hosted.

**Owner decisions:**
- The hotel's staff list may be sent to the panel: name, username, email, role, active, last sign-in, and permission
  changes. Never passwords.
- The licence limits modules for hotel admins too. Rosalyn's is not licensed for Gym, so Gym switches off there.
  Liwonde has all 13 modules, so nothing changes.
- The hotel's own admin may undo a deactivation made from the panel.

**Shipped 2026-11-10** (owner OK'd the push). Order: the hotels first, then the panel.
- **Panel:**
  - `app/hms.php`
  - `public/staff.php` (the HMS staff page)
  - `public/api/hms-staff.php` (the HMS report endpoint)
  - `migrations/009_hms_staff.sql` (applied to the live DB)
  - `scripts/tests/hms_test.php` (54 pure / 81 with `--db`)
  - `public/api/licence.php` (signed `staff_commands`; only ids above the HMS's `staff_done_id`)
  - `public/tenant.php` card
  - Business form: tenants must now tick at least one module besides Finance (`app/tenants.php`)
  - Guide section 16 and README
- **Hotels** (both repos, the same change):
  - `includes/licence-staff.php` (new)
  - `scripts/tests/licence_staff.php` (50 pure tests)
  - Edits to `includes/licence.php`, `includes/licence-guard.php`, `config/database.php`, `admin/admin-init.php`,
    `admin/includes/permissions.php`, `includes/ai/ai-core.php`, `scripts/licence-check.php`, `admin/licence.php`,
    `admin/includes/staff-invite-lib.php`
- **Independent review findings, all fixed before the push:**
  - Invitation links now use the site's own `site_url`, never the request's Host header. The nudge is unauthenticated.
  - An empty module list limits nothing on the HMS, and the panel refuses to save one.
  - The check-in sends `staff_done_id`, so changes already run never crowd out newer ones.
  - Staff changes run only straight after a successful check-in, from an answer at most 10 minutes old.
  - A permissions change carries the role and is refused if the role changed in the hotel first.
  - New-user details are cleaned as on the PMS Users page, and panel usernames are limited to `[A-Za-z0-9._@-]{3,100}`.
  - The licence lock names are per database.
  - The licence ceiling no longer depends on the session still being open.

**Follow-up 2026-11-10, after the push:**
- **Liwonde (tenant 2):** working. It checked in on the new version, and its staff list (3 staff) and catalogue arrived at
  08:43 DB time.
- **Rosalyn's (tenant 1):** checks in on the new version (pms-20261110), but its staff report never reached the panel
  (no `hms-ip` / `hms-t` rate-limit hit). The code is identical to Liwonde's, so the cause is on Rosalyn's server.
  Reading Rosalyn's live DB from the laptop was refused by the permission check. Most likely causes:
  - a ModSecurity request-body limit: Rosalyn's stored a permission row per key per user, so its report is big;
  - a different `LICENCE_URL` in production;
  - an exception.
- **The fix, shipped as the second hotel commit and panel migration 010:**
  - The HMS now sends only real permission changes, so the report is much smaller.
  - It records why the staff step stopped (`licence_staff_error`) and sends that as `staff_error` with every check-in.
  - The panel stores it in `cp_tenants.hms_error` and shows it in red on `staff.php`.
  - **Result:** Rosalyn's list arrived (13 staff) at 08:57 DB time, once the smaller report was live. Both hotels now
    report.

**Next steps:**
1. After the push, watch each hotel's next check-in: `staff.php?id=1` / `staff.php?id=2` should fill with staff. The
   "Ask for a fresh staff list now" button nudges the hotel.
2. Rosalyn's: Gym is not licensed, so it is now off there (the owner approved this). To restore it, tick Gym on
   tenant 1's business page.
3. Optional: a cPanel cron on each hotel running `php scripts/licence-check.php` hourly. Hotels otherwise check in only
   when staff load pages or when the panel nudges them.
4. Not done yet, and possible later: modules limit the guest website too (today only the staff side is limited).

## Shipped 2026-11-10: view-only free trials (owner 2026-11-10)
- **Panel:**
  - migration 011 `cp_tenants.trial_view_only` (**applied**; existing rows are 0, so Liwonde's current trial is
    unaffected)
  - `trial_set_view_only()` / `trial_view_only_now()` in `app/trials.php`
  - licence payload `trial_view_only`
  - a "View only" tile on the trial card
  - a "View only during the trial" checkbox (ticked by default) on the start / restart / new-business forms
  - guide-sales note
  - tests: trials 188 pure / 270 `--db`
- **Hotels:** the `view_only` permission, plus `includes/view-only.php` (see each hotel's `STATUS.md`).
- Pushed with the owner's OK: the hotels first, then the panel. Another session also works in the hotel folders: stage
  by name only.
- **Follow-up, pushed with the owner's OK:** the owner found that Export and New booking were still clickable on Liwonde's
  view-only trial. The fix is in the hotels only (`includes/view-only.php` and its test, in both repos). Exports are now
  refused during a view-only trial, and the greying reaches page headers and every link. See Liwonde's `STATUS.md`. It
  went live in both hotels; the panel needs no change.

## In progress: the hotel's Super Admin on the panel (owner 2026-11-11)
**Built, not pushed. Owner to apply migration 012, then OK the push.** The hotels shipped the Super Admin
(`admin_users.is_super_admin`, hotel migration 080, role stays `admin`; commits `0578bae` Rosalyn's / `5e8645d` Liwonde).
- **Hotels (both, identical, not pushed):** `includes/licence-staff.php` adds `super_admin` 0|1 to each staff report row
  (0 before hotel migration 080); `scripts/tests/licence_staff.php` checks it (53 pure).
- **Panel:**
  - `migrations/012_hms_super_admin.sql`: `cp_hms_staff.is_super_admin` (additive). **Written, NOT applied.** It must be
    applied before the panel code goes live: the staff report INSERT writes the column and would fail without it.
  - `app/hms.php`: the cleaner reads `super_admin` (missing = 0); effective permissions: super admin = all, admin = all
    minus denies; every change to a super admin refused; a role change on a limited admin refused (same order and
    meaning as the hotel's own refusals); the admin-permissions refusal reworded.
  - `public/staff.php`: Super Admin badge, no changes offered for them (pointer to the hotel's Users page); a limited
    admin is marked Limited, shows the count and the list of what is off, and their role cannot be changed.
  - `scripts/tests/hms_test.php`: 80 pure. Guide section 16 and `README.md` updated.
- **Order:** apply 012 (then the `--db` tests and a fresh export), then push the panel. The hotels can go at any time:
  the panel live today ignores the new `super_admin` field.

## Waiting on the owner
- Super Admin on the panel: apply migration 012, then OK the push (see above).

## Plan prices (set 2026-11-10 at the owner's request, live data, audited; the owner can change them on the Catalog page)
| Plan | Price (MWK) |
|---|---|
| PMS licence, monthly | 150,000 a month |
| IT Support, monthly | 200,000 a month |
| Website hosting, monthly | 35,000 a month |
| Website maintenance, monthly | 75,000 a month |
| PMS licence, yearly | 1,500,000 a year (about 2 months free) |

The annual discount setting is still 0%.

## Tests
- Pure, safe anywhere: `for f in scripts/tests/*_test.php; do php $f; done` (17 suites, all passing on 2026-11-10).
- On the live DB, in a single rolled-back transaction, `cp_*` tables only:
  - `php scripts/tests/clients_test.php --db` (114)
  - `php scripts/tests/trials_test.php --db` (261)
  - `php scripts/tests/hms_test.php --db` (75)

## How the HMS staff feature works (short)
1. The owner queues a change on `staff.php`. It goes into `cp_hms_commands`, and the panel nudges the HMS
   (`api/licence-ping.php` on the hotel).
2. The HMS checks in. The signed answer carries up to 10 pending changes. A change not picked up within 14 days lapses
   and is never applied late.
3. The HMS runs only change ids above `site_settings.licence_staff_done_id`, and only from an answer issued in the last
   2 hours. It protects the last active admin.
4. The HMS POSTs its results, the staff list and the roles/permissions catalogue to `api/hms-staff.php`. That endpoint
   uses the same tenant + key + domain check as the licence.
5. The page shows each change as Waiting → Sent → Done / Not done.
