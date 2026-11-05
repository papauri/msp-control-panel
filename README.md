# MSP licence control panel

Private panel for recording who has paid for the hotel PMS. Each PMS calls `api/licence.php` once a day and receives a signed status: active, grace (banner), read_only (view/check-out/export), suspended (admin locked). The guest website is never affected and no data is deleted. Vanilla PHP 7.4+ (8.3 fine), MySQL, openssl. No Composer, no sodium.

## Deploy on cPanel

1. Upload the whole folder to, for example, `/home/USER/msp-control-panel/` (not inside public_html).
2. Create a subdomain (for example `licence.yourdomain.com`) and set its **document root to `/home/USER/msp-control-panel/public`**. Only `public/` is web-visible; `app/`, `keys/`, `.env`, `logs/`, `storage/` must stay above it. Enable the free AutoSSL certificate and use HTTPS only.
3. Create a MySQL database and user in cPanel. Copy `.env.example` to `.env`, fill in `DB_*`, `APP_URL`. `chmod 600 .env`.
4. Run migrations (SSH or cPanel Terminal): `php scripts/migrate.php`. (Or paste `migrations/001_schema.sql` into phpMyAdmin.)
5. Make the signing key: `php scripts/make-keys.php`. It writes `keys/licence-private.pem` (chmod 600, outside `public/`) and prints the **public key**. Put the absolute path in `.env` as `PRIVATE_KEY_PATH`. Back the private key up offline. If it is lost, every PMS must be given a new public key.
6. Create your login: `php scripts/create-admin.php you@example.com`. Add the printed TOTP secret to an authenticator app (manual entry). There is no web sign-up.
7. Sign in at the subdomain, add each hotel, copy its API key (shown once) into that hotel's `.env`.

No cron job is needed. Rate limits and sessions clean themselves up.

## Security notes

- Sign-in needs password + 6-digit code. 5 failures lock the account for 15 minutes; 10 attempts per 15 minutes per IP. Codes are single use. Idle timeout 30 minutes, absolute 12 hours.
- Client IP is `REMOTE_ADDR` only. If you ever put a proxy in front, add trusted-proxy handling deliberately.
- If the private key is missing or unreadable the endpoint answers 503 and never signs.
- API keys are stored hashed. A lost key cannot be recovered, only replaced.
- Audit log records every change with before/after (never secrets).

## Tests

`php scripts/tests/status_test.php` and `totp_test.php`, `signing_test.php`, `verifier_test.php`. No database needed.

## PMS side

`client/licence-verify.php` is the reference verifier to embed in the PMS. The public key lives in code as a constant. See the header comment in that file for the integration contract.
