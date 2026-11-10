# MSP licence control panel

Private panel for recording who has paid for the hotel PMS. Each PMS calls `api/licence.php` once a day and receives a signed status: active, grace (banner), read_only (view/check-out/export), suspended (admin locked). The guest website is never affected and no data is deleted. Vanilla PHP 7.4+ (8.3 fine), MySQL, openssl. No Composer, no sodium.

## Deploy on cPanel

1. Upload the whole folder to, for example, `/home/USER/msp-control-panel/` (not inside public_html).
2. Create a subdomain (for example `licence.yourdomain.com`) and set its **document root to `/home/USER/msp-control-panel/public`**. Only `public/` is web-visible; `app/`, `keys/`, `.env`, `logs/`, `storage/` must stay above it. Enable the free AutoSSL certificate and use HTTPS only.
3. Create a MySQL database and user in cPanel (or, temporarily, share another database: all panel tables are prefixed `cp_` so they cannot clash. To move them later: Backups page > Download the panel's database (or `php scripts/export-db.php`, which writes to the git-ignored `database/` folder), import it into the new database in phpMyAdmin, change `DB_*` in `.env`. Step by step: Guide section 14 / EMERGENCY.md G). Copy `.env.example` to `.env`, fill in `DB_*`, `APP_URL`. `chmod 600 .env`.
4. Run migrations (SSH or cPanel Terminal): `php scripts/migrate.php`. (Or paste `migrations/001_schema.sql` into phpMyAdmin.)
5. Make the signing key: `php scripts/make-keys.php`. It writes `keys/licence-private.pem` (chmod 600, outside `public/`) and prints the **public key**. Put the absolute path in `.env` as `PRIVATE_KEY_PATH`. Back the private key up offline. If it is lost, every PMS must be given a new public key.
6. Create your login: `php scripts/create-admin.php you@example.com`. Add the printed TOTP secret to an authenticator app (manual entry). There is no web sign-up; further users are added by an admin on the Users page.
7. Sign in at the subdomain, add each hotel, copy its API key (shown once) into that hotel's `.env`.
8. Recommended: a daily cron job for backups (cPanel > Cron Jobs, once a day): `cd /home/USER/msp-control-panel && php scripts/backup-all.php >> logs/backup-cron.log 2>&1`. Without it the daily backup starts at the first sign-in of the day.

Rate limits and sessions clean themselves up.

## Users

Email is the username (no separate username column). Any admin adds a user (email + temporary password) on the Users page; the new person's first sign-in (code left empty) opens a 15-minute setup session that never carries `admin_id`: they add the shown key to an authenticator app, prove it with a code and choose their own password (`setup.php`). Reset gives a new temporary password and empties the 2-step secret (setup again); remove deletes the row (never yourself, never the last admin). Every add/reset/remove needs the acting admin's password + a fresh code and is audited. Sessions carry a fingerprint of the account's password hash + stored secret, so any reset or password change signs out other sessions. Forgot password by email (`forgot.php` → `reset.php`, `app/pwreset.php`, migration 007): on only when SMTP works and `APP_URL` is https. The link replaces the password only: finishing needs a current 6-digit code, so a mailbox alone never gets in; accounts not yet set up get no link. Same answer for every email; 5 requests per address and 3 per email an hour; only a SHA-256 of the 32-byte link secret is stored; 30 minutes, single use, newest link only, void after any other password change or reset, and after 5 wrong codes. Success signs in, ends other sessions, is audited and emails a "password changed" notice. A lost phone is still an admin reset or `php scripts/reset-2fa.php EMAIL`; `php scripts/reset-password.php EMAIL` remains the server-side way back in.

## Backups

Each business backs itself up on its own server (its PMS backup engine); the panel only asks and watches, so no client data reaches the panel. The panel puts `backup_requested_at` in the signed licence answer; the PMS runs its backup after its reply (at most once per 6 hours) and reports `{last_at, size, error_at, error_code, offsite_at, requested_seen}` (numbers and a code only) in its next check-in. A run happens on the first sign-in of the day, the Backups page button, or `scripts/backup-all.php` (cron). Each run also writes the panel's own `cp_` tables to `storage/backups/panel-*.cpbk`: gzip + AES-256-GCM with a key derived from the signing key (HKDF label `cp-backup-v1`), newest 14 kept. Request/report state lives in `storage/backup-state.json` (no schema change).

## Security notes

- Sign-in needs password + 6-digit code. 5 wrong tries for one email from one internet address block that email from that address for up to 15 minutes; the account itself is never locked, so nobody can lock the owner out by typing wrong passwords from elsewhere (unknown emails are counted the same way, so the block never shows which emails exist). 10 attempts per 15 minutes per IP. Codes are single use. Idle timeout 30 minutes, absolute 12 hours.
- The 2-step secret is stored encrypted (AES-256-GCM, key derived from the signing key's private number), so a copy of the database alone cannot make sign-in codes. Older plain secrets keep working and are encrypted at the next sign-in. The signing key now also opens sign-in: keep its backup. No web login needed to recover: `php scripts/reset-2fa.php you@example.com` gives a new 2-step secret (password unchanged).
- A new licence key is shown once. It is held in the session only for the redirect to its business page and is dropped on any other page, on a second view, and after 2 minutes.
- Client IP is `REMOTE_ADDR` only. If you ever put a proxy in front, add trusted-proxy handling deliberately.
- If the private key is missing or unreadable the endpoint answers 503 and never signs.
- API keys are stored hashed. A lost key cannot be recovered, only replaced.
- Audit log records every change with before/after (never secrets).

## Tests

`php scripts/tests/status_test.php` and `totp_test.php`, `signing_test.php`, `verifier_test.php`, `catalog_test.php`, `security_test.php`, `backup_users_test.php`. No database needed.

## Business types and modules

The PMS is sold to hotels, bars and restaurants, conference venues, gyms, shops and supermarkets. Each customer has a **business type** (Full Hotel, Hotel without restaurant, Bar / Restaurant, Conference Venue, Gym / Fitness, Retail / Shop, Supermarket, Custom) and a list of **licensed modules** (bookings, housekeeping, point of sale, stock, conference, gym, finance, website, the kitchen, bar, coffee and room-service stations, and the Assistant add-on). The lists live in `app/catalog.php` and mirror the PMS presets.

- Choosing a type on the customer page ticks that type's usual modules. Changing a box by hand switches the type to Custom. The Assistant add-on can be added to any type.
- Customers saved before this existed have no module list stored; the panel treats that as the full set for their type (Full Hotel by default).
- Both values are signed into the licence token (`business_type` string, `modules` array of keys; token version stays 1). `client/licence-verify.php` returns them as `business_type` and `modules` (both null when an older token carries none).
- Enforced in the PMS since 2026-11-10 (`includes/licence.php` `rh_licence_module_allowed`, used by `moduleEnabled()`): for signed-in staff an unlisted module is off, administrators included; `finance` always on; no list = nothing limited; guest website and cron never limited.
- `php scripts/migrate.php` records applied files in `cp_schema_migrations` and skips them on re-run.

## HMS staff and permissions (2026-11-10)

`app/hms.php`, `public/staff.php`, `public/api/hms-staff.php`, migration 009. The panel never reaches into a hotel: a change (deactivate, activate, role, permissions, create, invite) is queued in `cp_hms_commands`, added to the next **signed** licence answer as `staff_commands` (oldest 10 not yet done; lapses after 14 days), applied by the PMS (`includes/licence-staff.php`: only ids above its last one run, only from an answer issued in the last 2 hours, last active admin protected, hotel admin can undo), and confirmed by the PMS's report to `api/hms-staff.php` (same tenant + key + domain check as the licence; up to 512 KB) carrying results, the staff list (`cp_hms_staff`; no passwords) and the roles/permissions catalogue (`cp_tenants.hms_catalog`). The page nudges the PMS after each change. A deactivated user is signed out on their next request (PMS `rh_staff_session_guard`). Tests: `php scripts/tests/hms_test.php [--db]`.

Super admin (2026-11-11, PMS migration 080, panel migration 012 `cp_hms_staff.is_super_admin`): the PMS report flags each super admin (`super_admin` 0|1; missing = 0 for older PMS copies) and sends a super admin's limits on a plain administrator as that admin's `denies`. `hms_effective_permissions`: super admin = all; admin = all minus denies. `hms_validate_command` refuses every kind on a super admin and a role change on a limited admin (non-empty denies), with the same refusals as the PMS (`includes/licence-staff.php`); admin permissions are never set from the panel. Migration 012 must be applied before this code goes live, or the staff report INSERT fails.


## The Assistant (AI) package (2026-11-11)

`app/ai.php`, `public/ai.php`, migration 013 (`cp_ai_config`). Per business: the AI provider key (sealed at rest with AES-256-GCM under `keys_derive('cp-ai-v1')`; only the last 4 in clear), the models per role (Primary / Fast / Fallback, one role per model), the package (catalog plan, who pays the provider, monthly aim in US$) and the add-on in the licence (`ai_assistant` module). **Save and test** lists the provider's chat models with the key and pings every chosen model (5 tokens) from the panel. **Send to the hotel** is refused unless the last test passed in the last 24 hours for exactly this provider + key + models (`test_hash`, an HMAC under the panel's key). Sending queues one `cp_hms_commands` row of kind `ai_setup`: it travels in the signed licence answer with the key re-encrypted for that business (`ai_transport_seal`: AES-256-GCM under `hash_hkdf` of the licence key the business just sent, bound to the command id), so the answer the hotel stores never holds it readable. The hotel (`includes/licence-ai.php`) tests the key and models itself, changes nothing if that fails, then saves it its usual way; its staff report carries an `ai` block (on/off, provider, last 4, models, aim, this month's usage; never a key) shown under "At the hotel now", with the differences from what was sent (the hotel's admins may change it). The sealed key is wiped from the command once it is finished. Tests: `php scripts/tests/ai_test.php [--db]` (no network: fake transport).
## PMS side

`client/licence-verify.php` is the reference verifier to embed in the PMS. The public key lives in code as a constant. See the header comment in that file for the integration contract.
