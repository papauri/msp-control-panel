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
**LIVE 2026-11-11 (panel `63d8220`, owner OK).** Migration 012 applied after an export (`cp-export-20261110-170152.sql`);
`hms_test.php --db` 107/0 after the push (pure 80/0). Hotel migration 080 applied at both hotels and account #1
is super admin at both. The hotels shipped the Super Admin
(`admin_users.is_super_admin`, hotel migration 080, role stays `admin`; commits `0578bae` Rosalyn's / `5e8645d` Liwonde).
- **Hotels (both, identical, pushed: Rosalyn's `fc467ab`, Liwonde `09ce502`):** `includes/licence-staff.php` adds `super_admin` 0|1 to each staff report row
  (0 before hotel migration 080); `scripts/tests/licence_staff.php` checks it (53 pure).
- **Panel:**
  - `migrations/012_hms_super_admin.sql`: `cp_hms_staff.is_super_admin` (additive). **Applied 2026-11-11.** It must be
    applied before the panel code goes live: the staff report INSERT writes the column and would fail without it.
  - `app/hms.php`: the cleaner reads `super_admin` (missing = 0); effective permissions: super admin = all, admin = all
    minus denies; every change to a super admin refused; a role change on a limited admin refused (same order and
    meaning as the hotel's own refusals); the admin-permissions refusal reworded.
  - `public/staff.php`: Super Admin badge, no changes offered for them (pointer to the hotel's Users page); a limited
    admin is marked Limited, shows the count and the list of what is off, and their role cannot be changed.
  - `scripts/tests/hms_test.php`: 80 pure. Guide section 16 and `README.md` updated.
- **Order:** apply 012 (then the `--db` tests and a fresh export), then push the panel. The hotels can go at any time:
  the panel live today ignores the new `super_admin` field.

## Shipped: the Assistant (AI) package from the panel (owner 2026-11-11)
**Owner asked:** manage each business's AI model and subscription from the panel (the Assistant comes as a package with
the HMS): enter their AI provider key and test it from the panel BEFORE sending it to the hotel. The hotel's own admins
may still override it afterwards (Assistant Settings in the HMS).

**Panel:**
- `migrations/013_ai_config.sql`: `cp_ai_config`, one row per business. **Applied to the live DB 2026-11-11** (additive;
  the live code ignores it). `--db` tests after it: ai 102/0, hms 107/0, clients 114/0, trials 270/0. Export refreshed
  (`database/cp-export-20261110-203633.sql`, laptop clock) and `database/README.txt` updated.
- `app/ai.php` (new; loaded by `app/bootstrap.php`): providers, key sealed at rest (`keys_derive('cp-ai-v1')`), the
  panel's own test (lists models, pings every chosen model; injectable transport), deploy rules, the hotel's report.
- `public/ai.php` (new page "Assistant (AI)"): Subscription (add-on in the licence on/off = the `ai_assistant` module;
  plan from the Catalog; who pays the provider; monthly aim US$; this month's usage from the hotel), Provider key and
  models (Save and test), Send to the hotel (only when the last test passed in the last 24 h for exactly this
  provider + key + models; optional "switch it on", which needs the add-on), At the hotel now (and what differs).
- `app/hms.php`: `hms_answer_fields(..., $licenceKey)` sends an `ai_setup` command with the AI key re-encrypted for that
  business (AES-256-GCM under `hash_hkdf` of the licence key it just sent, bound to the command id); a key the panel
  cannot open marks it failed; the sealed key is wiped from finished commands (`ai_wipe_finished`).
- `public/api/licence.php` passes `$keyIn`; `public/api/hms-staff.php` stores the report's `ai` block;
  `public/staff.php` hides `ai_setup` rows; `public/tenant.php` "Assistant (AI)" card; guide section 16b; README.
- `scripts/tests/ai_test.php`: 72 pure / 102 with `--db` (no network: fake provider).
- No catalog plan was created: the owner sets the price. Add an "Assistant add-on, monthly" item on the Catalog page,
  then choose it under Package and bill it with a recurring invoice.

**Hotels (both, identical; Liwonde `da9f64c`, Rosalyn's `bb323de`):** `includes/licence-ai.php` (new), `scripts/tests/licence_ai.php` (new, 30 pure;
shares a fixed encryption vector with the panel's test), `includes/licence-staff.php` (kind `ai_setup`; the report
carries `ai`). The hotel tests the key and every chosen model itself and changes NOTHING if that fails; then saves the
key the Assistant's usual way (`rh_ai_store_key`, needs `APP_ENCRYPTION_SALT`), refreshes models, sets the roles, the
monthly aim and (if asked) switches the Assistant on. No hotel schema change.

**Shipped with the owner's OK on 2026-11-11:** both hotels pushed first, then the panel (an old hotel would ignore an
`ai_setup` and leave it "Sent" until it lapses, so the hotels went first). All 18 pure panel suites pass. NOT yet run
against a real provider or a live hotel: the first real send is that test.

**First real use (owner):** on the live panel open a business > Manage the Assistant, paste the key, Save and test (the
newest models are picked and tested), Send. Watch "Sent" turn Done and "At the hotel now" fill in.

## Shipped: always the newest models (owner 2026-11-11)
**Owner asked:** "Always pick up the most latest models for the providers." Panel only (`app/ai.php`, `public/ai.php`,
`scripts/tests/ai_test.php`, guide 16b, README); no migration, no hotel change.
- Every Save and test now moves to the newest models before it pings: no Primary yet -> the newest mid-size Primary and
  Fallback and small Fast are picked; otherwise each chosen model moves to the newest of its family and size (e.g.
  claude-sonnet-5 -> claude-sonnet-5-5). The test saves what it ended with and its fingerprint covers that, so a send
  (needs a passed test < 24 h) always carries the newest models. The page and the flash say what was picked or moved.
- Owner rules kept: Gemini 4 never listed or chosen; big models (Opus/Fable/Argon/Pro/Ultra or US$15+/M out) never picked
  for chat unless nothing else is offered; previews, `-latest` aliases and special models (codex, search, research, vision,
  o-series, open-weight) never picked automatically (still choosable by hand). A picked model the provider refuses (404)
  is replaced by the next newest, at most 3 test replies a role.
- No opt-out (the owner said "always"): a hand-picked older version moves to the newest at the next test.
- `AI_HOTEL_PRICES` copies the hotels' `includes/ai/model-catalog.json` prices (the test fails if they drift, when the hotel
  repo sits beside the panel). A picked model not in it gets a warning: the hotel uses it but counts its cost as US$0 until
  a price is typed in its Assistant Settings > Models (the hotel has no price for e.g. gpt-5.1 or claude-fable-5-2 yet).
  Possible follow-up: carry prices in `ai_setup` (hotel change) or refresh the hotels' catalog.
- Tests: ai 112 pure / 144 with `--db`; all 18 pure suites pass; hms `--db` 107/0.
- Pushed 2026-11-11 (owner's standing OK: commit and push after each piece of work).

## Task, not started: HMS security and system health from the panel (owner 2026-11-11)
**Do this on the owner's NON-work device only.** A first build was started on the work laptop on 2026-11-11 and fully
undone at the owner's request (no files, no DB tables, nothing committed). The laptop's antivirus quarantined test files
holding web-shell samples, so: never write malware samples to disk, assemble them in memory only.

**Owner asked:** spot vulnerabilities and unusual activity in every HMS through the panel, strict rules plus AI when
available. Security leads and AI leads do everyday checks. Watch unusual sign-ins, geo (countries), changes to key details
in each business (bank / mobile-money / email / site address), "virus definitions" checked. Logic must last decades.

**Design worked out (reuse it):**
- Rules decide, with stable ids; AI only summarises for the leads (redact usernames / IPs, mark hotel text as data), never
  closes a finding. Alert only: nothing at a hotel is changed automatically.
- What ages is data: a versioned definitions file (sensitive-setting name patterns, known-bad code patterns, PHP support
  end dates, private paths to probe, review-by date), signed by the panel and fetched by each hotel when newer.
- Hotel side (both repos, new `includes/licence-security.php`, called after the staff step in `rh_licence_staff_after_check`
  and in `scripts/licence-check.php`; no DDL): hourly report of `admin_activity_log` rows after a cursor (login_success /
  failed / blocked, password resets, super_admin_changed, page_action), HMAC fingerprints of watched `site_settings` with a
  masked hint (never values), file integrity against `.git/index` (stat quick check hourly, full read daily; baseline list
  if no git), unknown runnable files (esp. in upload folders), pattern hits, PHP / disk / .env permission facts, ClamAV date.
- Panel side: migration for findings / events / state / daily sign-off / AI reviews / geo ranges; report endpoint with the
  same key + domain check; staff-list diffs (new admin, role raised, email changed); rules on `cp_licence_checks` (new server
  IP, refused check-ins, silence); outside probes of each site (cert, https redirect, headers, `.env` / `.git` / backups not
  readable); hourly cron with email to leads for High/Critical and a daily digest; geo from an offline DB-IP country list
  (no IP sent outside); a Security page with the daily sign-off.
- Note: `scripts/tests/security_test.php` already exists (another suite): pick a different name for new tests.

## Waiting on the owner
- The Assistant (AI): add a catalog plan for the add-on (price is the owner's call), then do the first real send
  (business > Manage the Assistant) and check "Sent" turns Done and "At the hotel now" fills in.
- Super Admin on the panel: look at the staff page after the hotels' next check-in.

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
- Pure, safe anywhere: `for f in scripts/tests/*_test.php; do php $f; done` (18 suites, all passing on 2026-11-11).
- On the live DB, in a single rolled-back transaction, `cp_*` tables only:
  - `php scripts/tests/clients_test.php --db` (114)
  - `php scripts/tests/trials_test.php --db` (270)
  - `php scripts/tests/hms_test.php --db` (107)
  - `php scripts/tests/ai_test.php --db` (144; never calls a real AI provider)

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
