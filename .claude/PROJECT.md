# MSP control panel: map card (updated 2026-11-23)
WHAT: Private PHP panel that licenses and bills the owner's hotel PMS/HMS installs (signed licence answers, trials, invoices, ProManaged billing).
STACK: PHP 7.4+ (8.3 used), MySQL (tables prefixed cp_), openssl, vendored PHPMailer and TCPDF in lib/, cPanel hosting, GitHub (main).
LIVE SYSTEM: yes   (details: see STATUS.md and README.md, never copied here)
RUN / TEST / LINT: no run script or Composer/npm file found. Deploy: cPanel pulls main. Migrate: `php scripts/migrate.php`.
  Pure tests (no DB): `php scripts/tests/<name>.php` (e.g. status_test.php, signing_test.php, backup_users_test.php).
  `--db` test variants touch the live DB: do not run them without the owner's OK. LINT: none found.
CRITICAL AREAS:
- money -> app/billing.php, app/billing_mail.php, app/recurring.php, app/reports.php, app/pdf.php, app/xlsx.php, public/invoices.php, public/statement.php, public/recurring.php, public/reports.php, public/assets/billing.js
- quantities -> app/catalog.php, public/recurring.php
- time -> app/trials.php, app/recurring.php, app/maintenance.php, app/status.php, app/automation.php
- access -> app/auth.php, app/users.php, app/totp.php, app/csrf.php, app/ratelimit.php, public/login.php, public/logout.php, public/users.php, public/staff.php, public/setup.php
- security -> app/keys.php, app/signing.php, app/env.php, app/audit.php, app/pwreset.php, public/forgot.php, public/reset.php, public/keys.php, public/account.php, .htaccess, public/.htaccess
- data -> app/backup.php, app/dbexport.php, app/db.php, app/tenants.php, app/clients.php, app/deploy.php, public/backups.php, public/deploy.php, scripts/backup-all.php, scripts/export-db.php, scripts/deploy-check.php
- messages -> app/mailer.php, app/billing_mail.php, app/email_templates.php, public/outbox.php, lib/phpmailer/
- decision -> app/status.php, app/trials.php, app/hms.php, app/domain.php, client/licence-verify.php, public/api/licence.php
- state -> app/maintenance.php, public/maintenance.php, migrations/, scripts/migrate.php, database/
- integrations -> public/api/licence.php, public/api/hms-staff.php, app/hms.php, app/ai.php, public/ai.php, app/deploy.php, client/licence-verify.php
KEY FILES:
- README.md - setup on cPanel, security notes
- BILLING.md - billing design
- STATUS.md - live state, in-progress work, owner decisions
- EMERGENCY.md / MOVING.md - recovery and moving the database
- app/bootstrap.php - app boot and loading
- public/api/licence.php - signed licence endpoint called by each HMS
- client/licence-verify.php - licence verifier copy for hotels
- app/signing.php - licence signing with the private key
- app/keys.php - key handling
- migrations/ - schema, applied in order by scripts/migrate.php
- scripts/tests/ - test suites (_harness.php shared)
- .claude/DEPLOY_HOTEL_SPEC.md - hotel side of Update from Remote
CONVENTIONS: no Composer, no sodium, no build step; migrations applied by the agent, then `php scripts/export-db.php` refreshes database/ (git-ignored).
DEEPER MAPS: BILLING.md (billing design) | STATUS.md (live state)
