# Moving the panel to its own database and domain

Today the panel runs on a temporary address and keeps its tables (all named `cp_...`) inside the Liwonde Sun Hotel
database. This is the checklist for moving it to a database and web address of its own. About 30 minutes. The
businesses keep working throughout: they use their last licence answer for up to 35 days.

## What moves

| What | Where it is | Why it matters |
|---|---|---|
| The panel's records (businesses, clients, invoices, quotes, payments, users, audit, settings, outbox) | the `cp_` tables | everything you have recorded |
| `keys/licence-private.pem` | the panel folder (outside the website folder) | signs every licence answer, opens the 6-digit codes and the panel's own backups. **Never make a new one.** |
| `.env` | the panel folder | database, email and address settings |
| `storage/` | the panel folder | the panel's own encrypted backups and the backup status (optional: they rebuild) |
| the code | GitHub (private repo `msp-control-panel`) | cPanel Git Version Control pulls it |

## Steps

1. **New database** — new cPanel > MySQL Databases: create a database and a user with a strong password; add the user with
   All Privileges. Note the database name, user and password.
2. **Take the records** — in the OLD panel: Backups > Download the panel's database > *As it is right now* (password + a new
   code). Check the file's last line reads `-- complete: every table exported`. Also, in the old panel's folder, download
   `keys/licence-private.pem` and `.env` (cPanel > File Manager > right-click > Download). Keep all three offline.
3. **Code** — new cPanel > Git Version Control: clone the `msp-control-panel` repository (or upload the folder). The address
   (subdomain, e.g. `licence.promanaged-it.com`) must point at the folder's `public` directory; turn on AutoSSL (HTTPS).
4. **Records in** — new cPanel > phpMyAdmin > the new database > Import > the file from step 2 > Go.
   (A brand-new empty panel instead: `php scripts/migrate.php` creates every table.)
5. **Key and settings** — upload `licence-private.pem` into the new panel folder's `keys/` folder (permissions 600). Create
   `.env` from `.env.example`, copying from the old `.env`, then change:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` → the new database
   - `APP_URL` → the new https address
   - `PRIVATE_KEY_PATH` → the full path of the uploaded key
   - `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE` → the ProManaged mailbox (today they are borrowed from
     the Liwonde database, which the new database does not have); `MAIL_SETTINGS_FROM_DB=0`
   - keep `MAIL_TEST_TO` until you have checked a test email, then remove it.
6. **Check** — new cPanel > Terminal: `cd ~/msp-control-panel && php scripts/move-check.php --smtp`. Fix every `FIX` line;
   it ends with `Ready`. It also shows the key's fingerprint: run it on the OLD panel too, the two must match.
7. **Sign in** — open the new address, sign in (your codes keep working), check businesses, clients, invoices, payments and
   users. Settings > Send a test email. Backups > Back up everything now.
8. **Cron jobs** (new cPanel > Cron Jobs) — the check prints them with the right folder:
   - daily backup: `php scripts/backup-all.php`
   - daily billing (recurring invoices, reminders, trial emails): `php scripts/billing-daily.php`
9. **Businesses** — for each one: its cPanel > File Manager > its `.env` > change `LICENCE_URL=` to
   `https://<new address>/api/licence.php` > Save. Then its Licence page > Check now. (The move check prints the exact line.)
10. **Old panel** — after a week of everything working: switch the old address off, and in phpMyAdmin delete ONLY the tables
    starting with `cp_` from the Liwonde database. Never any other table.

## If something goes wrong

Nothing is lost while the old panel and the Liwonde tables are still there: point the businesses' `LICENCE_URL` back at the
old address. See EMERGENCY.md for the rest.
