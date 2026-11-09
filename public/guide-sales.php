<?php
// The Sales & Billing guide (owner 2026-11-09): how the department works, screen by screen, in plain words.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);

view_head('Sales and billing guide');
?>
<h1>Sales and billing guide</h1>
<p class="muted">Everything ProManaged IT sells and bills, from the first quotation to the receipt. Only signed-in admins can see this page. The licence side (businesses, signing key, backups, users) is in the <a href="guide.php">main guide</a>.</p>

<h2 id="how">1. How the department works</h2>
<p>One person runs it; the panel does the routine work.</p>
<ol>
    <li><strong>Client</strong>: anyone you bill. A company or an individual, a hotel on our system or someone who only bought a laptop.</li>
    <li><strong>Quotation</strong> &rarr; the client accepts &rarr; <strong>invoice</strong> (one click).</li>
    <li><strong>Payment</strong> recorded &rarr; a <strong>receipt</strong> and a thank-you go out by themselves.</li>
    <li><strong>Regular billing</strong> (hosting, support, the system licence) is a <strong>recurring invoice</strong>: the panel makes each invoice on its date.</li>
    <li>Late payers get <strong>reminders</strong> on fixed days; a hosted business also steps down to read-only and then locked (main guide, section 2).</li>
    <li>Anything that goes to a client is either sent <strong>automatically</strong> or waits in the <strong>Outbox</strong> for one click, as you choose on <strong>Automations</strong>.</li>
</ol>
<p><strong>Your daily routine (5 minutes):</strong> open <strong>Billing</strong> &rarr; the board at the top shows what is due today, what is overdue, what waits in the Outbox and which recurring invoices come up this week. Approve the Outbox. Record any payments that came in. That is all.</p>

<h2 id="clients">2. Clients</h2>
<ul>
    <li><strong>Clients</strong> &rarr; search, or <strong>Add client</strong>: company or individual, contact name, email (where invoices go), CC emails (comma separated), phone, address, tax number, currency.</li>
    <li>Every hosted business (hotel) is also a client. Its details stay the same in both places: change them on either page.</li>
    <li>A client's page shows their <strong>Account</strong> (owed and overdue per currency), invoices and quotations, payments, recurring invoices and every email sent to them, with <strong>New invoice</strong>, <strong>New quote</strong>, <strong>New recurring invoice</strong> and <strong>Record payment</strong>.</li>
    <li>On a quotation or invoice you can also make a new client straight from the client box (<strong>New client</strong>).</li>
</ul>

<h2 id="items">3. Saved items, hardware and plans</h2>
<p><strong>Settings</strong> &rarr; <strong>Saved items</strong>: the things you sell, ready to drop onto a quotation or invoice.</p>
<ul>
    <li><strong>Category</strong>: service, hardware, software, licence, hosting, domain or other. Hardware usually has an <strong>SKU</strong> (your stock code) and a <strong>unit</strong>.</li>
    <li><strong>Cost price</strong> (what it costs you): optional. When it is filled in, invoices and Reports show your <strong>profit</strong>. You only ever see it in the panel; it is never printed on a client's document.</li>
    <li>A <strong>plan</strong> is a saved item that repeats (every 1, 3 or 12 months): the system licence, IT support, hosting, maintenance. Plans are what recurring invoices are made from.</li>
</ul>

<h2 id="quotes">4. Quotations</h2>
<ol>
    <li><strong>Billing</strong> &rarr; <strong>New quotation</strong> (or <strong>New quote</strong> on a client).</li>
    <li>Pick the client. Add lines: <strong>Add saved item</strong>, <strong>Add licence subscription</strong> (a hosted business's plan and every module it has), or <strong>Add line</strong> for anything else. Discount and tax are optional. <em>Valid until</em> defaults to Settings &rsaquo; quotation valid days.</li>
    <li><strong>Send quotation</strong>: the email goes with the PDF attached; add a personal note if you like.</li>
    <li>When they say yes: <strong>Mark accepted</strong> (they get a confirmation, see section 10) and <strong>Convert to invoice</strong>. <strong>Mark declined</strong> when they say no.</li>
</ol>

<h2 id="invoices">5. Invoices</h2>
<ol>
    <li><strong>Billing</strong> &rarr; <strong>New invoice</strong>. Client, lines, discount, tax, due date (Settings &rsaquo; invoice due days), notes.</li>
    <li><strong>Extend the licence by (months)</strong> (hosted businesses): when this invoice is paid in full, the business's paid-until date moves on by that many months.</li>
    <li><strong>Send invoice</strong> &mdash; the client gets the email with the PDF (your ProManaged IT template, with the bank details and the remittance slip). A draft becomes <em>sent</em>. <strong>Mark as sent</strong> does the same without an email (if you handed it over).</li>
    <li><strong>Send reminder</strong> at any time; automatic reminders are in section 10.</li>
    <li><strong>Download PDF</strong> for printing or WhatsApp.</li>
</ol>
<ul>
    <li>A sent invoice can still have its lines corrected while nothing is paid; a paid or void invoice cannot change. <strong>Void invoice</strong> is only for an invoice with no payment on it.</li>
    <li>Numbers run on by themselves (next number: Settings &rsaquo; Numbering).</li>
    <li>An invoice that totals zero (for example a free month) counts as paid as soon as it is sent.</li>
</ul>

<h2 id="payments">6. Payments and receipts</h2>
<ol>
    <li>On the invoice (or the client): <strong>Record payment</strong> &mdash; amount, how it was paid (bank transfer, mobile money, cash, card, cheque, other) and the reference (transfer or receipt number).</li>
    <li>Part payments are fine; the invoice shows what is left. Paying more than is owed is refused. Pressing the button twice never records it twice.</li>
    <li>The client automatically gets a <strong>thank-you with the receipt</strong> attached, and when the invoice is paid in full a <strong>thank you for your business</strong> (section 10).</li>
    <li>Every payment row has <strong>Download receipt</strong> and <strong>Send receipt</strong>. A receipt always shows the balance as it was right after that payment.</li>
</ol>
<p class="muted">A licence payment recorded on a business's own page (without an invoice) also gets a receipt. If that business has an open invoice that extends its licence, record the payment on the invoice instead; the panel says so, so a licence is never extended twice.</p>

<h2 id="owing">7. What is owed, statements and payment requests</h2>
<ul>
    <li><strong>Billing</strong> &rarr; the <em>Owing</em> tab: every client that owes, how much, how much is overdue and the oldest due date.</li>
    <li>A client's <strong>Statement</strong>: every invoice and payment with a running balance for any period &mdash; <strong>Download PDF</strong> or <strong>Send statement</strong>.</li>
    <li><strong>Send payment request</strong>: one polite email listing everything outstanding, with each open invoice and the statement attached.</li>
    <li>Different currencies are never added together. A client who owes in MWK and EUR gets a statement per currency, and a payment request is refused (send the invoices instead).</li>
</ul>

<h2 id="recurring">8. Recurring invoices (monthly billing)</h2>
<ol>
    <li>On the client: <strong>New recurring invoice</strong>. Give it a title ("Website hosting"), how often (every 1, 3 or 12 months), the next date, an optional end date, payment days, and the lines (a plan, saved items, or <strong>Add licence subscription</strong>).</li>
    <li>Choose what happens on the date: <strong>send it</strong> automatically, put it in the <strong>Outbox</strong> for your click, or only make a <strong>draft</strong>.</li>
    <li>The daily job (section 10) makes each invoice on its date and moves the next date on (a schedule on the 31st follows the end of shorter months).</li>
    <li><strong>Make the next invoice now (draft)</strong> if you need it early.</li>
</ol>

<h2 id="trials">9. Free trials</h2>
<p>A business (a hotel, lodge, restaurant&hellip;) can run the system free for a set number of days. One free trial per business.</p>
<ol>
    <li><strong>Add business</strong> and choose a free trial (7, 14 or 30 days, or your own up to 90), or open a business that has never paid &rarr; <strong>Start free trial</strong>. The system works fully during the trial.</li>
    <li>Its staff see <strong>"Free trial: N days left"</strong> in the last 7 days. The panel's lists show <em>Free trial &ndash; N days left</em>.</li>
    <li>Emails go by themselves: a welcome to the trial, reminders 7, 3 and 1 days before the end (with what it costs to continue), and on the day after the end "your free trial has ended". Each can be switched on <strong>Automations</strong>.</li>
    <li>After the last day it does not stop at once: 0 grace days, then 3 days view-only (they can still look and export), then the staff area locks. The guest website keeps working and no data is ever deleted. Change those days in <strong>Settings</strong> (Grace / Read-only days after a trial).</li>
    <li><strong>Extend trial</strong> by any number of days, or <strong>End the trial now</strong>.</li>
    <li><strong>Convert to paid</strong>: pick a plan (monthly or yearly). This makes their recurring invoice and the first invoice, gives the business the normal step-down days (Settings &rsaquo; Normal grace / read-only days, 14 and 14), and sends a thank-you. The lock lifts as soon as the first invoice is paid. A payment recorded on the business page during a trial also ends the trial.</li>
</ol>
<p><strong>Billing</strong> board: trials ending in the next 7 days. <strong>Reports</strong>: trials started, converted and lost, and the conversion rate.</p>
<p class="muted">Sales tips: 14 days is the usual sweet spot. Call the business on the day the 3-day reminder goes out; most trials are won in the last week. Offer yearly at about two months free (Settings &rsaquo; yearly discount %, about 17).</p>

<h3 id="plans">Plans and subscriptions</h3>
<p>A plan is a saved item that repeats (Settings &rsaquo; Saved items &rsaquo; <em>Plan (bill this every&hellip;)</em>: every 1, 3 or 12 months). Five are ready with price 0 for you to fill in: PMS licence &ndash; Monthly and Yearly, IT Support &ndash; Monthly, Website hosting &ndash; Monthly, Website maintenance &ndash; Monthly.</p>
<p>On any client: <strong>Subscribe</strong> &rarr; pick the plan, monthly or yearly (the yearly discount is added as its own line), any extra lines, the start date and whether invoices go automatically, wait in the Outbox or stay drafts. It becomes a recurring invoice (section 8).</p>

<h2 id="automations">10. Automations and the Outbox</h2>
<p><strong>Automations</strong> lists every email the panel can send by itself. Each one is <strong>Send automatically</strong>, <strong>Ask me first</strong> (it waits in the Outbox) or <strong>Off</strong>.</p>
<ul>
    <li><strong>Thank you for your payment</strong> (with the receipt) &mdash; automatic.</li>
    <li><strong>Thank you for your business</strong> (invoice paid in full) &mdash; automatic.</li>
    <li><strong>Quotation accepted</strong> (confirmation) &mdash; automatic.</li>
    <li><strong>Welcome</strong> (a new client: how billing works and how to pay) &mdash; ask me first.</li>
    <li><strong>Reminders</strong> on the days in Settings &rsaquo; Reminders (default 3 days before the due date, on the day, 7 and 14 days after) &mdash; ask me first.</li>
    <li><strong>Free trial started / ending / ended</strong> and <strong>Thank you for choosing us</strong> (section 9) &mdash; automatic.</li>
    <li><strong>Recurring invoices</strong> follow each schedule's own choice.</li>
</ul>
<p><strong>Outbox</strong>: everything waiting for you, with a preview (subject, the first lines, attachments). <strong>Send</strong> (add a note if you like) or <strong>Skip</strong>. A failed send stays there with <strong>Retry send</strong>. Nothing is ever sent twice for the same payment, invoice or day.</p>
<p><strong>The daily job</strong> makes the recurring invoices and the reminders. Set it once in cPanel &rarr; Cron Jobs (once a day):<br>
<code>cd /home/CPANELUSER/msp-control-panel &amp;&amp; php scripts/billing-daily.php &gt;&gt; logs/billing-daily.log 2&gt;&amp;1</code><br>
<strong>Automations</strong> &rarr; <strong>Run the daily job now&hellip;</strong> shows what it would do, then runs it.</p>

<h2 id="reports">11. Reports and Excel</h2>
<ul>
    <li><strong>Reports</strong>: pick the period (this month, last month, this year, or your own dates).</li>
    <li>Cards per currency: invoiced, collected, outstanding, overdue and profit (where you entered cost prices; "partial" when some lines have none).</li>
    <li><strong>Aged debt</strong>: what each client owes, split into current, 1-30, 31-60, 61-90 and over 90 days late. Chase from the right.</li>
    <li>Sales by month, by client, by category and by product, and the list of payments.</li>
    <li>Every table has <strong>Export to Excel</strong>; <strong>Export everything</strong> gives one workbook with a sheet each (invoices, invoice lines, payments, quotations, clients, aged debt, sales by month, by product). Every export is written in the audit log.</li>
</ul>

<h2 id="settings">12. Settings</h2>
<ul>
    <li><strong>Company</strong>: the name, address, emails, phone and website printed on every document and email.</li>
    <li><strong>Payment details</strong>: the bank accounts block under every invoice (exactly as you type it).</li>
    <li><strong>Numbering, dates and tax</strong>: next invoice and quotation numbers, payment days, quotation valid days, tax rate and its name.</li>
    <li><strong>Reminders</strong>: the reminder days.</li>
    <li><strong>Email signature</strong>, and <strong>Send a test email</strong> to check the mail works.</li>
    <li><strong>Saved items</strong>: section 3.</li>
</ul>

<h2 id="test-mode">13. Test mode and going live</h2>
<p>While the line <code>MAIL_TEST_TO=</code> is in the panel's <code>.env</code>, <strong>every</strong> email goes only to that address, and its subject says who it would have gone to. Nothing reaches a client. To go live: cPanel &rarr; File Manager &rarr; <em>msp-control-panel/.env</em> &rarr; delete the <code>MAIL_TEST_TO</code> line &rarr; Save, then Settings &rarr; Send a test email.</p>

<h2 id="data">14. Client data</h2>
<ul>
    <li>The panel keeps what billing needs: client names, contacts, addresses, what you sold them and what they paid. Nothing else.</li>
    <li>Every invoice, payment, email, export and change is in the audit log with who did it.</li>
    <li>Excel exports and PDFs hold client details: keep them on your own computer, not in shared folders or chats.</li>
    <li>Main guide, section 16 lists what the law asks of you (agreement with each client, how long you keep records, what to do if data is lost).</li>
</ul>
<?php
view_foot();
