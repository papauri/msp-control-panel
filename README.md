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

Email is the username (no separate username column). Any admin adds a user (email + temporary password) on the Users page; the new person's first sign-in (code left empty) opens a 15-minute setup session that never carries `admin_id`: they add the shown key to an authenticator app, prove it with a code and choose their own password (`setup.php`). Reset gives a new temporary password and empties the 2-step secret (setup again); remove deletes the row (never yourself, never the last admin). Every add/reset/remove needs the acting admin's password + a fresh code and is audited. Sessions carry a fingerprint of the account's password hash + stored secret, so any reset or password change signs out other sessions. No e-mail reset (the panel has no mailer): `php scripts/reset-password.php EMAIL` and `php scripts/reset-2fa.php EMAIL` are the server-side ways back in.

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
- Both values are signed into the licence token (`business_type` string, `modules` array of keys; token version stays 1). `client/licence-verify.php` returns them as `business_type` and `modules` (both null when an older token carries none). Enforcement in the PMS is separate.
- `php scripts/migrate.php` records applied files in `cp_schema_migrations` and skips them on re-run.

## PMS side

`client/licence-verify.php` is the reference verifier to embed in the PMS. The public key lives in code as a constant. See the header comment in that file for the integration contract.
