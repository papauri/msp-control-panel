# Billing (owner 2026-11-09) — build contract

Sales and payments for ProManaged IT inside the licence panel: invoices, quotations, what each business owes,
reminders, statements, receipts, and branded emails with the PDF attached. Every document and email uses ONE
template: the ProManaged IT invoice (see "Template" below). Company details are editable on the Settings page.

Stack rules (unchanged): vanilla PHP 7.4+, PDO prepared statements, `e()` on output, `csrf_field()`/`csrf_require()`
on every POST, `auth_require($pdo)` on every page, `audit_log()` for every change (never secrets), CSP
`default-src 'self'` (no inline script/style attributes that need `unsafe-inline`: put JS in `public/assets/*.js`,
styles in `public/assets/app.css`). No Composer. Third-party code goes in `lib/` (outside `public/`).
Database: the tables below already exist (migration 004 applied). NO other schema change.

## Tables (migration 004_billing.sql)
- `cp_tenants` + `contact_name`, `billing_email`, `cc_emails` (comma separated), `contact_phone`, `billing_address` (multi-line).
- `cp_settings(setting_key PK, setting_value)` — company profile etc. (keys below).
- `cp_documents` — one row per invoice or quote: `kind` invoice|quote, `number` (unique per kind), `tenant_id` (NULL =
  a prospect), `customer_name`, `customer_contact`, `customer_email`, `customer_address`, `status`
  (invoice: draft|sent|paid|void; quote: draft|sent|accepted|declined|expired|converted), `issue_date`, `due_date`
  (invoice due / quote valid until), `currency`, `salesperson`, `notes`, `subtotal`, `discount`, `tax_rate`, `tax`,
  `total`, `amount_paid`, `extend_months` (when an invoice is paid in full, extend the business's paid_until by N months),
  `source_id` (invoice made from quote id), `sent_at`, `last_reminder_at`, `reminder_count`, `created_by`.
- `cp_document_items(document_id, sort, qty, item, description, unit_price, line_total)`.
- `cp_catalog_items(item, description, unit_price, currency, is_active, sort)` — saved lines to add quickly.
- `cp_payments` + `document_id`, `method` — a payment may be against an invoice (`months` 0 and
  `paid_until_before = paid_until_after` when it does not extend the licence).
- `cp_email_log(tenant_id, document_id, kind, to_email, subject, status sent|failed|test, error, attachment, admin_id, created_at)`.

## Money rules
- All amounts DECIMAL(2 dp). Compute in PHP with `round(x, 2)`; compare with `BILLING_TOLERANCE = 0.005`, never `==` on floats.
- `line_total = round(qty * unit_price, 2)`; `subtotal = Σ line_total`; `tax = round((subtotal - discount) * tax_rate / 100, 2)`;
  `total = subtotal - discount + tax`. Balance due = `total - amount_paid`.
- Owed by a business = Σ balance of its invoices with status `sent` (draft and void never count). Overdue = due_date < today.
- A payment against an invoice: amount ≤ balance + tolerance; `amount_paid += amount`; status → `paid` when balance ≤ tolerance;
  on reaching paid with `extend_months > 0` and a `tenant_id`: extend `cp_tenants.paid_until` exactly as the existing
  "Record payment" does in `public/tenant.php` (from max(paid_until, today)), one transaction, audited.
- Void: only an invoice with `amount_paid = 0`. Paid/void invoices are not editable. Sent invoices are editable only in
  their lines while unpaid (re-sending is the owner's choice).
- Quote → invoice: copies customer + lines into a new draft invoice, quote status `converted`, invoice `source_id`.

## Settings keys (`billing_setting($key)` returns the stored value or the default below)
company_name "ProManaged IT" · company_address "Offaly,\nIreland" · company_emails "info@promanaged-it.com\nsales@promanaged-it.com"
company_phone "" · company_website "https://promanaged-it.com" · salesperson "John Paul" · default_currency "MWK"
invoice_next_number "170134" · quote_next_number "Q-1001" · invoice_due_days "7" · quote_valid_days "30" · tax_rate "0" · tax_label "VAT"
thank_you "Thank you for your business."
payment_details (multi-line, printed under the totals; lines of only dashes are kept):
```
Eur/$ Account
-----------------
Bank Name: Permanent TSB
Account Name: PROMANAGED IT
IBAN: IE04IPBS99072135995698
BIC: IPBSIE2D
Bank account number: 35995698
Branch code: 990721
Bank Code: IPBS
Branch Address: Address: 56-59 ST. STEPHEN'S GREEN, DUBLIN, D02 H489

MWK Account
-----------------
Standard Bank
Branch: CAPITAL CITY
Account Name: JOHN PAUL CHIRWA
Account Numbers: 9100006400512
----------------------------------
Please contact us for more information about payment options.
```
email_signature "John Paul\nProManaged IT\ninfo@promanaged-it.com" · reminder_days "3,0,7,14" (days BEFORE due (3), ON due (0),
AFTER due (7,14); negative not used) · auto_reminders "1" · brand_color "#D40000" (the template red) · brand_navy "#1B3A5C" (logo navy).

## Shared functions — app/billing.php (owner: agent DATA)
```
billing_setting(string $key): string
billing_settings_all(): array                              // key => value (defaults merged)
billing_settings_save(PDO $pdo, array $kv, int $adminId): void
billing_company(): array   // ['name','address_lines'=>[],'emails'=>[],'phone','website','payment_details','thank_you','salesperson','brand_color','brand_navy','logo_path'=>APP_ROOT.'/public/assets/logo.png']
billing_money(float $amount, string $currency): string     // "MWK602,000.00" (no space, 2 dp, thousands commas)
billing_date(?string $ymd): string                          // "17/01/2026" or ''
billing_next_number(PDO $pdo, string $kind): string        // reserves and returns the next number (locked, increments the setting)
billing_doc(PDO $pdo, int $id): ?array                      // document row + 'items' => [rows] + 'balance' + 'is_overdue' + 'tenant' => cp_tenants row|null
billing_doc_save(PDO $pdo, array $doc, array $items, int $adminId): int   // insert/update + recalculates totals; returns id
billing_record_payment(PDO $pdo, int $docId, float $amount, string $reference, string $method, int $adminId): array // ['ok'=>bool,'error'=>string,'payment_id'=>int,'paid_in_full'=>bool]
billing_void(PDO $pdo, int $docId, int $adminId): string    // '' or error
billing_quote_to_invoice(PDO $pdo, int $quoteId, int $adminId): int
billing_tenant_summary(PDO $pdo, int $tenantId): array      // ['owed','overdue','currency','open'=>[docs],'last_payment'=>row|null]
billing_owed_all(PDO $pdo): array                           // per tenant: id, name, currency, owed, overdue, oldest_due, billing_email
billing_statement(PDO $pdo, int $tenantId, ?string $from = null, ?string $to = null): array
     // ['tenant'=>row,'from','to','lines'=>[['date','type'=>'invoice|payment','ref','description','debit','credit','balance']],'opening','closing','currency','open_invoices'=>[docs]]
billing_subscription_lines(array $tenant, int $months): array  // invoice lines for the licence: item "<plan> licence", description lists the
     // business type label and licensed module labels (app/catalog.php), qty = months, unit_price = monthly_fee
billing_due_reminders(PDO $pdo, string $today): array       // invoices due a reminder today per reminder_days (not already reminded today)
```

## PDF — app/pdf.php (owner: agent PDF). TCPDF copied into lib/tcpdf (core + helvetica fonts only).
```
pdf_document(array $doc, array $company): string     // invoice or quote (title "Invoice" / "Quotation"); remittance slip only on invoices
pdf_statement(array $statement, array $company): string  // "Statement" — same frame; table Date | Type | Ref | Description | Debit | Credit | Balance; "Amount owed"
pdf_receipt(array $payment, array $doc, array $company): string // "Receipt" — payment against an invoice; amount, method, reference, balance left
pdf_filename(string $title, string $number): string  // "ProManaged IT Invoice 170133.pdf"
```

## Email — app/mailer.php + app/email_templates.php + app/billing_mail.php (owner: agent EMAIL). PHPMailer copied into lib/phpmailer.
SMTP from .env `SMTP_HOST/PORT/USER/PASS/SECURE`; when SMTP_HOST is empty and `MAIL_SETTINGS_FROM_DB=1`, read the shared
hotel database table `email_settings` (smtp_host, smtp_port, smtp_username, smtp_password [is_encrypted=1 → `SELECT decrypt_setting(?)`],
smtp_secure). From = `MAIL_FROM_EMAIL` / `MAIL_FROM_NAME`. **`MAIL_TEST_TO` set → every email goes ONLY there** (subject prefixed
"[TEST to original@address]"), logged as status `test`. Never print or log the SMTP password.
```
mail_send(array $m): array   // $m: to (array of emails), cc (array), subject, html, text, attachments [['name','bytes','mime']], reply_to
                             // returns ['ok'=>bool,'error'=>string,'to'=>actual recipients]
email_render(string $kind, array $data, array $company): array   // ['subject','html','text']
     kinds: invoice, quote, reminder (before/on/after due wording from $data['days']), payment_request (owing summary),
            statement, receipt, test
billing_send(PDO $pdo, string $kind, int $id, array $opts, int $adminId): array
     // id = document id (invoice/quote/reminder/payment_request/receipt=payment id) or tenant id (statement);
     // $opts: to (override list), cc, message (personal note shown at the top); builds PDF + email, sends, logs cp_email_log,
     // sets sent_at / status draft→sent / reminder counters, audits. Returns ['ok','error','to']
```
Email design: the same brand as the PDF — white card on a light grey page, logo top (embedded inline as CID image), red
(#D40000) title bar like the PDF's "Invoice" box, a summary table (number, date, due, amount, balance), the personal note,
payment details block, signature, footer with company emails + website. Table-based HTML, inline CSS (email clients),
max-width 640px, readable on phones, plain-text alternative.

## Pages (owner: agent DATA)
- `public/invoices.php` — tabs Invoices | Quotations | Owing; filters (status, business), totals (owed, overdue); "New invoice", "New quotation".
- `public/document.php?id=` / `?new=invoice|quote&tenant=` — edit customer + lines (add line, pick a saved item, "Add licence
  subscription" from billing_subscription_lines), discount, tax, notes, due/valid date, extend_months; actions: Save, Download PDF,
  Send (email form: to/cc prefilled from the business, personal note), Send reminder, Record payment (amount, method, reference),
  Receipt (download/send), Void, Convert quote to invoice, Mark accepted/declined. Email history for the document.
- `public/statement.php?tenant=` — statement on screen; Download PDF; Send statement; Send payment request (owing).
- `public/settings.php` — company profile, payment details, numbering, due/valid days, tax, reminders, email signature, saved
  items (add/edit/deactivate), "Send a test email" (goes to MAIL_TEST_TO or the admin's own email), SMTP status (host/user only).
- `public/tenant.php` — new fields contact_name, billing_email, cc_emails, contact_phone, billing_address; a Billing card
  (owed, overdue, open invoices, links: New invoice, Statement).
- Nav (app/view.php): add "Billing" (invoices.php) and "Settings" (settings.php).
- `scripts/billing-reminders.php` (owner: agent EMAIL) — cron: sends due reminders (billing_due_reminders) when auto_reminders=1.

## Template (the owner's PDF "ProManaged IT Invoice 170133.pdf")
A4 portrait, Helvetica. Top-left: the title ("Invoice") in bold red (#D40000) ~22pt inside a red-outlined box. Top-right: logo
(public/assets/logo.png, ~22mm wide). Left block with a grey vertical bar at its left edge: company name bold, then address
lines, then emails. Right block, two columns: "Date:", "Invoice No.:", "Due Date:", "Salesperson:" (quote: "Quotation No.:",
"Valid Until:"). Below-left: customer contact name, then business name (then address lines if any). Items table: header row
red (#D40000) with white bold text: Qty (right) | Item | Description | Unit Price (right) | Total (right); thin black borders; rows
alternate white / light grey (#E6E6E6); minimum table height so a short invoice still looks full. Under the table, right
aligned bold: "Total  MWK602,000.00", "Balance Due  MWK602,000.00" (plus Subtotal / Discount / VAT lines only when non-zero,
"Amount Paid" when > 0). Then bold "Thank you for your business." and the payment_details block in bold small text. Then a full-
width red bar, "REMITTANCE" centred bold, and a 2-column list: Invoice No., Customer Name, Date, Amount Enclosed (= balance
due); a red rule at the bottom. Money format "MWK602,000.00". Dates dd/mm/YYYY.

---

# Part 2 — Sales & Billing department (owner 2026-11-09)

"Completely be able to do all the billing from here", for ANY client (a company or an individual, hosting with us or
not — we also sell hardware etc.), track it with Excel exports, and send automated emails (thank you for payment, thank
you for your business, ...). Run as autonomously as possible, the way the ProManaged Proposals app runs its departments:
automations do the work, and anything that goes to a customer either goes automatically or waits in an **Outbox** for one
click, per the owner's setting. Same stack rules as Part 1. Migration 005 is applied; no other schema change.

## Tables added (migration 005_sales.sql)
- `cp_clients(id, kind company|individual, name, contact_name, email, cc_emails, phone, address, tax_number, currency,
  tenant_id NULL UNIQUE (the hosted business it is), source, notes, is_active)`. Every hosted business already has its
  client row (tenant_id set). **The client is now who a document/payment belongs to**; tenant_id stays only to extend a
  licence. Keep the tenant's billing contact and its client row in step when either is edited.
- `cp_documents.client_id`; `cp_payments.client_id` (and `tenant_id` now NULL-able); `cp_email_log.client_id`.
- `cp_catalog_items` + `category` (service | hardware | software | licence | hosting | domain | other), `sku`, `unit`, `cost_price`.
- `cp_document_items` + `catalog_item_id`, `cost_price` (copied from the catalog item when picked; gives profit).
- `cp_recurring(client_id, tenant_id, title, interval_months, next_date, end_date, currency, due_days, extend_months,
  items_json [{qty,item,description,unit_price,cost_price,catalog_item_id}], notes, send_mode auto|approve|draft, is_active,
  last_document_id, last_run_at)`.
- `cp_outbox(kind, ref_id, client_id, document_id, reason, opts_json, status pending|sent|failed|skipped, dedupe_key UNIQUE,
  error, decided_by, created_at, decided_at)` — automated emails waiting for approval (or a record of auto-sent ones).
  A row being sent is held as `failed` with `error = OUTBOX_SENDING_NOTE` (app/automation.php) and becomes `sent` only after
  the email went; for 10 minutes nobody else can send or skip it, after that the Outbox shows it as "Interrupted".

## Agents and ownership (do not edit another agent's files)
**Agent CLIENTS** — app/clients.php, app/recurring.php, public/clients.php, public/client.php, public/recurring.php, and
changes to app/billing.php, public/document.php, public/invoices.php, public/statement.php, public/settings.php,
public/tenant.php, app/view.php (nav: Billing, Clients, Reports, Outbox, Settings), public/assets/billing.js, app.css.
```
client_find(PDO, int $id): ?array
client_for_tenant(PDO, int $tenantId): ?array               // creates the linked client if missing
client_save(PDO, array $c, int $adminId): int               // validates; fires automation_event('client_created') for new ones
client_summary(PDO, int $clientId): array                   // per currency: owed, overdue, currency (main), other[], open[], last_payment
client_statement(PDO, int $clientId, ?string $from, ?string $to, ?string $currency): array  // same shape as billing_statement + 'client'
recurring_save(PDO, array $r, int $adminId): int
recurring_due(PDO, string $today): array                    // active schedules with next_date <= today and end_date not passed
recurring_run_one(PDO, int $id, int $adminId, string $today): array   // creates the invoice (status draft), advances next_date by
     // interval_months calendar-safely (31 Jan + 1 month = 28/29 Feb), returns ['ok','document_id','send_mode','error']
```
billing_doc_save takes `client_id` (required for new documents; a prospect = a client created on the fly from the customer
fields); `tenant_id` is set from the client's tenant_id. billing_doc adds `client` (row). billing_record_payment works for
any client (tenant_id only for a hosted business) and, after commit, calls `automation_event($pdo, 'payment_recorded',
['payment_id'=>..,'document_id'=>..,'paid_in_full'=>bool])`; billing_set_status to `accepted` calls
`automation_event($pdo, 'quote_accepted', ['document_id'=>..])`. Guard every call with `function_exists('automation_event')`.
Statement / payment request are per CLIENT (`statement.php?client=`; `?tenant=` maps to its client); billing_send kinds
'statement' and 'payment_request' take a CLIENT id (AUTOMATION adapts billing_mail.php). Catalog in settings.php gets
category, SKU, unit, cost price. Document editor: client picker (+ "New client" inline: company or individual), item picker
grouped by category, profit shown to the owner. Billing board at the top of invoices.php: due today, overdue, outbox
waiting (link), recurring due in 7 days.

**Agent REPORTS** — app/xlsx.php (pure-PHP XLSX writer with its own ZIP writer: CRC32 + gzdeflate; ZipArchive is NOT
installed), app/reports.php, public/reports.php. Period picker (this month, last month, this year, custom); cards
(invoiced, collected, outstanding, overdue, profit where cost known); aged debt (current, 1-30, 31-60, 61-90, 90+) per client
and currency; sales by month, by client, by product/category; payments list. Every table has "Export to Excel" (.xlsx);
"Export everything" = one workbook (Invoices, Invoice lines, Payments, Quotations, Clients, Aged debt, Sales by month, Sales
by product). Never add different currencies together. Read-only queries only.
```
xlsx_build(array $sheets): string   // [['name'=>'Invoices','columns'=>[['title'=>'Date','type'=>'string|number|money|date','width'=>12]],'rows'=>[[...]]]]
xlsx_stream(string $bytes, string $filename): void
```

**Agent AUTOMATION** — app/automation.php, public/outbox.php, public/automations.php, scripts/billing-daily.php, and
changes to app/email_templates.php, app/billing_mail.php (new kinds + client-based recipients), scripts/billing-reminders.php
(thin wrapper calling the daily run's reminder step).
New email kinds: `thank_you_payment` (receipt PDF), `thank_you_business` (invoice paid in full; PDF shows PAID), `welcome`
(new client), `quote_accepted` (confirmation + quote PDF), `recurring_invoice` (invoice wording + "your regular invoice for
<title>"). Recipients come from the client (email + cc); billing_send must work for any client.
```
automation_modes(): array          // kind => off|approve|auto. Keys auto_thank_payment, auto_thank_business, auto_welcome,
                                   // auto_quote_accepted, auto_reminders; recurring uses each schedule's send_mode
automation_event(PDO, string $event, array $data): void  // payment_recorded → thank_you_payment (+ thank_you_business if paid in
                                   // full); quote_accepted; client_created → welcome. auto → send now (never throws; errors logged);
                                   // approve → cp_outbox (dedupe_key "thank_you_payment:<payment_id>" etc.); off → nothing
outbox_add(PDO, string $kind, int $refId, array $opts, string $reason, string $dedupe): int
outbox_decide(PDO, int $id, string $action /* send|skip */, int $adminId, string $message = ''): array
billing_daily(PDO, string $today, bool $dryRun = false): array  // 1. recurring → invoice → send/outbox/draft per send_mode
                                   // 2. due reminders per auto_reminders  3. summary; idempotent (lock file storage/billing-daily.lock + dedupe)
```
Defaults: auto_thank_payment=auto, auto_thank_business=auto, auto_welcome=approve, auto_quote_accepted=auto,
auto_reminders=approve. automations.php: one row per automation (mode, what it sends, when, last 10 sent). outbox.php:
pending items with preview (subject, first lines, attachments), Send / Skip / note; history tab. Cron:
`scripts/billing-daily.php` once a day (replaces billing-reminders.php in the guide).

---

# Part 3 — Free trials and subscription plans (owner 2026-11-09)

"Make a business like the hotels on a free demo trial that locks after the trial ends, depending on its length. And
billing monthly, e.g. for support or a service." Migration 006 is applied: `cp_tenants.trial_until`, `trial_started`,
`trial_converted_at`; `cp_catalog_items.billing_interval` (months; NULL = one-off item).

## Trials (licence)
- Start a trial when adding a business (or on an existing one that has never paid): length in days (presets 7 / 14 / 30,
  custom 1-90). Sets trial_started = today, trial_until = today + days, paid_until = trial_until, and the after-trial
  step-down from settings `trial_grace_days` (default 0) and `trial_readonly_days` (default 3: they can still view and export,
  then the staff area locks; data is never deleted). Status words everywhere: "Free trial – N days left" / "Trial ended".
- Extend a trial (+N days, audited), end it now, or convert to paid: convert = choose a plan (catalog item with
  billing_interval) or the business's monthly fee → creates a recurring schedule (Part 2) starting today, the first invoice
  (draft or sent per the owner's choice), restores the business's normal grace/read-only days from settings
  `default_grace_days` / `default_readonly_days` (14 / 14), sets trial_converted_at; paid_until moves only when the first invoice
  is paid (extend_months on it). A trial with an unpaid first invoice keeps the trial's end date.
- The signed licence answer adds `trial` (bool, true while trial_until is set and not converted) and `trial_until`
  (Y-m-d|null). Old sites ignore them.
- Automations (Part 2 engine, modes off/approve/auto, dedupe per tenant+day): `trial_started` (welcome to the trial: what is
  included, how long, who to call), `trial_ending` at settings `trial_reminder_days` (default "7,3,1" days before end, with a
  quotation/offer to continue), `trial_ended` (the day after: what happens now, how to continue, payment details), `trial_converted`
  (thank you for choosing us). Defaults: trial_started auto, trial_ending auto, trial_ended auto, trial_converted auto.
- Billing board + client page show trials ending in the next 7 days; Reports gets "Trials: started / converted / lost" and
  conversion rate for the period.

## Plans (subscriptions for any client: PMS licence, IT support, hosting, maintenance, ...)
- A plan = a catalog item with billing_interval (1 = monthly, 3 = quarterly, 12 = yearly). Settings › Saved items can mark an
  item as a plan. "Subscribe" on a client page: pick a plan (+ optional extra lines, start date, send mode) → recurring schedule.
  Yearly billing of a monthly plan: optional discount % (setting `annual_discount_pct`, default 0) shown as a discount line.
- Seed (only if no plan exists, prices 0 for the owner to set): "PMS licence – Monthly", "IT Support – Monthly",
  "Website hosting – Monthly", "Website maintenance – Monthly", "PMS licence – Yearly" (12).

## Hotel side (PMS, both hotels, Rosalyn's is the source; tests at Liwonde only)
includes/licence.php: keep `trial` / `trial_until` from a verified payload; staff banner "Free trial: N days left — contact
<LICENCE_CONTACT> to continue" during the last 7 days of a trial, and the existing grace/read-only/suspended wording after it
(say "Your free trial has ended" instead of "payment overdue" when trial was true). Ship with scripts/ship.php from Rosalyn's.
