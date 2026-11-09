<?php
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);

view_head('Guide');
?>
<h1>Licence panel guide</h1>
<p class="muted">How to use this panel. Only signed-in admins can see this page. Invoices, quotations, payments, receipts, clients, free trials, plans, automations and reports: see the <a href="guide-sales.php">Sales and billing guide</a>.</p>

<h2>1. What this panel does</h2>
<p>This panel keeps a record of each business that uses the hotel system (PMS). It records what each business pays for.</p>
<p>Each business's system checks in with this panel by itself every hour. When you change a business here (status, payment, type or modules), the panel also nudges that business to check in straight away, so the change shows within seconds. The panel tells that system whether the business is Active, Grace, Read only or Suspended.</p>
<ul>
    <li>The guest website is never switched off.</li>
    <li>Data is never deleted.</li>
</ul>

<h2>2. The four statuses</h2>
<table>
    <thead><tr><th scope="col">Status</th><th scope="col">What it means</th><th scope="col">What staff can do</th></tr></thead>
    <tbody>
        <tr>
            <td>Active</td>
            <td>Paid. The paid-until date has not passed.</td>
            <td>Everything.</td>
        </tr>
        <tr>
            <td>Grace</td>
            <td>The paid-until date has passed. A warning banner shows. Default: 14 days.</td>
            <td>Everything, with the warning shown.</td>
        </tr>
        <tr>
            <td>Read only</td>
            <td>Grace has ended. Default: 14 more days.</td>
            <td>Look at records, check guests out, and export. Cannot add new bookings or sales.</td>
        </tr>
        <tr>
            <td>Suspended</td>
            <td>Read only has ended (or you set it by hand). The business is not paid up.</td>
            <td>The staff admin area is locked. The guest website stays up and no data is deleted; paying (and recording it here) unlocks it.</td>
        </tr>
    </tbody>
</table>
<p>The dates move the status step by step on their own: Active, then Grace, then Read only, then Suspended.</p>
<p>An <strong>override</strong> is a status set by hand, with a reason. The dates are ignored while an override is set. See section 7.</p>

<h2>3. The dashboard columns</h2>
<p>Open <strong>Businesses</strong> to see the list.</p>
<ul>
    <li><strong>Business</strong>: the business name and short name.</li>
    <li><strong>Type</strong>: the business type chosen when it was added.</li>
    <li><strong>Domain</strong>: the website address the licence works on.</li>
    <li><strong>Plan</strong>: the plan the business pays for.</li>
    <li><strong>Paid until</strong>: the date the current payment runs to.</li>
    <li><strong>Status</strong>: Active, Grace, Read only or Suspended. The label "override" shows when a status was set by hand.</li>
    <li><strong>Last check-in</strong>: the last time the business's system checked in.</li>
    <li><strong>PMS version</strong>: the version of the hotel system the business is running.</li>
</ul>

<h2>4. Add a business</h2>
<ol>
    <li>Click <strong>Add business</strong> in the top menu.</li>
    <li>Type a <strong>short name</strong>. Use lowercase letters only. You cannot change it later.</li>
    <li>Type the <strong>business name</strong>.</li>
    <li>Type the <strong>website domain</strong>. It must match exactly the address the business's site uses. The licence only works on that domain.</li>
    <li>Choose the <strong>business type</strong>. See section 5.</li>
    <li>Tick the <strong>modules</strong> the business uses.</li>
    <li>Choose the <strong>plan</strong>, and type the <strong>monthly fee</strong> and the <strong>currency</strong>.</li>
    <li>Set the <strong>paid-until</strong> date.</li>
    <li>Set the <strong>grace days</strong> and the <strong>read-only days</strong>. The defaults are 14 and 14.</li>
    <li>Click save.</li>
    <li>Copy the <strong>licence key (API key)</strong> straight away. It is shown <strong>once only</strong>. Paste it into that business's settings file.</li>
</ol>
<p class="muted">If the licence key is lost, make a new one for that business. The business page shows the last 4 characters of the current key so you can check it matches; the business's admin can also paste a new key on their own Licence page. The old key stops working as soon as the new one is made.</p>
<p class="muted">If something goes wrong: "That short name is already used by another business." Choose a different short name.</p>

<h2>5. Business types and modules</h2>
<p>There are seven business types, plus <strong>Custom</strong>:</p>
<ul>
    <li>Full Hotel</li>
    <li>Hotel no restaurant</li>
    <li>Bar / Restaurant</li>
    <li>Conference Venue</li>
    <li>Gym / Fitness</li>
    <li>Retail / Shop</li>
    <li>Supermarket</li>
</ul>
<ol>
    <li>Choose a type. Its modules are ticked for you.</li>
    <li>To change a module, tick or untick its box. The type then changes to <strong>Custom</strong>.</li>
    <li>The <strong>Assistant</strong> is an add-on. You can tick it on any type.</li>
</ol>
<p>There are 13 modules:</p>
<ul>
    <li>Bookings &amp; Reservations</li>
    <li>Housekeeping</li>
    <li>Point of Sale (till)</li>
    <li>Stock &amp; Inventory</li>
    <li>Conference &amp; Events</li>
    <li>Gym &amp; Fitness</li>
    <li>Finance &amp; Payments</li>
    <li>Website &amp; CMS</li>
    <li>Kitchen Display</li>
    <li>Bar Display</li>
    <li>Coffee Bar Display</li>
    <li>Room Service Station</li>
    <li>Assistant</li>
</ul>

<h2>6. Record a payment</h2>
<ol>
    <li>Open the business from the <strong>Businesses</strong> list.</li>
    <li>Find the <strong>Record payment</strong> box.</li>
    <li>Type the number of <strong>months</strong> paid.</li>
    <li>Type the <strong>amount</strong> and a <strong>reference</strong> (for example, the bank transfer number).</li>
    <li>Click save.</li>
</ol>
<p>The paid-until date moves forward by the number of months. It counts from today, or from the current paid-until date, whichever is later.</p>
<p>The status changes back to Active once the new date is in the future.</p>

<h2>7. Override the status</h2>
<p>Use an override for an agreed extension, or to suspend a business at once.</p>
<ol>
    <li>Open the business.</li>
    <li>Find the <strong>Status override</strong> box.</li>
    <li>Choose the status you want.</li>
    <li>Type a <strong>reason</strong>. The reason is kept in the audit log.</li>
    <li>Click save.</li>
</ol>
<ul>
    <li>A forced <strong>Active</strong> stops counting after 35 days with no check-in from the business. After that the status goes back to the dates.</li>
    <li>To go back to the dates, clear the override.</li>
</ul>

<h2>8. Audit log</h2>
<p>Open <strong>Audit log</strong> in the top menu. It lists every change and every sign-in, newest first.</p>
<ul>
    <li><strong>When</strong>: the date and time.</li>
    <li><strong>Who</strong>: the admin who made the change.</li>
    <li><strong>Action</strong> and <strong>Business</strong>: what was done, and to which business.</li>
    <li><strong>Change</strong>: click <strong>Show</strong> to see the record before and after the change.</li>
</ul>
<p class="muted">Licence keys (API keys) are never stored in the audit log.</p>

<h2>9. Sign in</h2>
<ol>
    <li>Type your <strong>email</strong> and <strong>password</strong>.</li>
    <li>Open your authenticator app and type the current <strong>6-digit code</strong>.</li>
</ol>
<ul>
    <li>After 5 wrong tries from the same place, sign-in with that email is blocked <strong>from that place only</strong> for up to 15 minutes. Your account is never locked, so you can still sign in from another network (for example your phone's mobile data).</li>
    <li>Your phone clock must be set to automatic. If the time is wrong, the 6-digit code will not work.</li>
</ul>
<p><strong>First sign-in</strong> (you were just added, or an admin reset you): type your email and the temporary password you were given and leave the code empty. The next page shows a key to add to your authenticator app; type the code it shows and choose your own password. You have 15 minutes to finish. See section 15.</p>
<p class="muted">If something goes wrong: wait 15 minutes, then sign in again. Forgot your password or lost your phone? Ask another admin to reset you on the <strong>Users</strong> page. If you are the only admin, see 12 F.</p>

<h2>10. Moving to a real domain</h2>
<p>When a business moves its website to a new address, update the licence the same day.</p>
<ol>
    <li>Open the business.</li>
    <li>Change the <strong>Domain</strong> to the new address, exactly as the site uses it.</li>
    <li>Click save.</li>
</ol>
<p>Until the domain is changed, the licence will not work on the new address.</p>

<h2>11. Signing key</h2>
<p>The panel signs every answer it gives a business, so a business cannot fake "Active". This needs a signing key, made once.</p>
<ol>
    <li>Open <strong>Signing key</strong> in the top menu.</li>
    <li>If it says there is no key yet, click <strong>Create signing key</strong>. This is done once and the key is never replaced.</li>
    <li>Copy the <strong>Public key</strong> shown on the page (all of it). It goes into the business system's code. It is not secret.</li>
    <li>Back up the key: on the same page, enter your password and a new 6-digit code and click <strong>Download backup</strong>. Keep the file offline. Never email it.</li>
</ol>
<p>The key also protects your sign-in: the secret behind your 6-digit codes is stored encrypted with it. If the key file is lost, codes stop working until it is put back from the backup (12 B), or until you give yourself a new code (12 F).</p>
<p>Until a key exists, every check-in is answered with "unavailable" and nothing changes at the businesses.</p>

<h2 id="emergency">12. If something goes wrong (no programmer needed)</h2>
<p>Everything below is done in <strong>cPanel</strong> only. The same steps are in the file <strong>EMERGENCY.md</strong> in the panel's GitHub repository, so you can still read them if this panel does not open.</p>

<h3>First: nobody is locked out straight away</h3>
<ul>
    <li>If this panel stops working, every business keeps using the last answer it got. Nothing changes for <strong>35 days</strong>.</li>
    <li>After that, each business follows its own <strong>Paid until</strong> date. A business that has paid ahead keeps working.</li>
    <li>So you have weeks, not minutes. Do not rush.</li>
</ul>

<h3>A. Switch the licence OFF for one business (about 1 minute)</h3>
<p>Use this if a business is paused by mistake, has paid but you cannot record it, or this panel is down for a long time.</p>
<ol>
    <li>Log in to <strong>cPanel</strong> and open <strong>File Manager</strong>.</li>
    <li>Click <strong>Settings</strong> (top right), tick <strong>Show Hidden Files (dotfiles)</strong>, click <strong>Save</strong>.</li>
    <li>Open that business's website folder. (Not sure which? cPanel &rarr; <strong>Git Version Control</strong> shows it as the <em>Repository Path</em>.)</li>
    <li>Right-click the file <strong>.env</strong> &rarr; <strong>Edit</strong> &rarr; <strong>Edit</strong> again.</li>
    <li>Find the line that starts with <code>LICENCE_TENANT=</code>.</li>
    <li>Type a <code>#</code> at the very start of that line, so it reads <code>#LICENCE_TENANT=...</code></li>
    <li>Click <strong>Save Changes</strong>.</li>
</ol>
<p>That's it. The business works normally straight away: no banner, no locks, no checks. Its data is not touched.</p>
<p><strong>To switch it back ON later:</strong> do the same steps and delete the <code>#</code>. Then open this panel and check the business shows the right status.</p>
<p><strong>All businesses?</strong> Do step A for each one.</p>

<h3>B. This panel shows an error or a blank page</h3>
<ol>
    <li>cPanel &rarr; <strong>Git Version Control</strong> &rarr; <strong>Manage</strong> next to <em>msp-control-panel</em> &rarr; <strong>Pull or Deploy</strong> tab &rarr; <strong>Update from Remote</strong>. Try the panel again.</li>
    <li>Still broken? cPanel &rarr; <strong>File Manager</strong> (hidden files shown) &rarr; open the <em>msp-control-panel</em> folder. Check these are there:
        <ul>
            <li><strong>.env</strong> (the settings file). If it is missing, upload your saved copy.</li>
            <li><strong>keys/licence-private.pem</strong> (the signing key). If it is missing, upload your offline backup into the <em>keys</em> folder. <strong>Do not</strong> click "Create signing key" again: a new key would not match the businesses.</li>
        </ul>
    </li>
    <li>Page says it cannot reach the database? See C.</li>
</ol>

<h3>C. The panel's records are lost (database)</h3>
<ol>
    <li>cPanel &rarr; <strong>phpMyAdmin</strong> &rarr; click the database the panel uses (its name is on the <code>DB_NAME</code> line of the panel's .env).</li>
    <li>Click <strong>Import</strong> &rarr; <strong>Choose File</strong> &rarr; pick your newest panel database file (see D) &rarr; <strong>Go</strong>.</li>
    <li>Open the panel and check your businesses are back.</li>
</ol>
<p class="muted">No downloaded file, but the panel still opens? <strong>Backups</strong> &rarr; Download the panel's database &rarr; choose one of the dated backups kept on the server.</p>

<h3>D. Backups to keep (do these now, and after big changes)</h3>
<ol>
    <li><strong>Signing key and settings</strong> (once, and again if you change the panel's settings): open <strong>Signing key</strong> in the top menu, enter your password and a new 6-digit code, click <strong>Download backup</strong>. One file holds the private key, the public key and the panel's settings file. Keep it on a USB stick or in your password manager. Never email it. <em>The key is the one thing that cannot be made again.</em> (If the panel will not open: cPanel &rarr; File Manager &rarr; <em>msp-control-panel/keys</em> &rarr; right-click <strong>licence-private.pem</strong> &rarr; <strong>Download</strong>, and the same for <em>.env</em>.)</li>
    <li><strong>Panel records</strong> (after recording payments): <strong>Backups</strong> &rarr; Download the panel's database &rarr; <em>As it is right now</em>, with your password and a new code. Keep the file with the key, offline. (If the panel will not open: cPanel &rarr; <strong>phpMyAdmin</strong> &rarr; the panel's database &rarr; <strong>Export</strong> &rarr; <strong>Custom</strong> &rarr; tick only the tables that start with <code>cp_</code> &rarr; <strong>Go</strong>.)</li>
    <li>The panel also keeps its own encrypted copies on the server every day (section 13). Those protect against a mistake, not against losing the server: the offline file above does that.</li>
</ol>

<h3>E. Moving this panel to a new web address</h3>
<ol>
    <li>cPanel &rarr; <strong>Domains</strong> &rarr; create the new address and point its folder (Document Root) at the <em>msp-control-panel</em> folder.</li>
    <li>For each business: cPanel &rarr; File Manager &rarr; that business's <strong>.env</strong> &rarr; Edit &rarr; change the <code>LICENCE_URL=</code> line to the new address, ending in <code>/api/licence.php</code> &rarr; Save.</li>
    <li>Open each business's <strong>Licence</strong> page and press <strong>Check now</strong> to confirm it connects.</li>
</ol>

<h3>F. Your 6-digit code is not accepted</h3>
<ol>
    <li>Check your phone's clock is set to automatic, wait for a new code, and try once more.</li>
    <li>Tried 5 times? Wait 15 minutes, or sign in from another network (for example your phone's mobile data).</li>
    <li>Still not working: check <em>msp-control-panel/keys/licence-private.pem</em> is there (12 B). Your codes are protected with it; put it back from your offline backup if it is missing.</li>
    <li>Lost the phone? Another admin can reset you on the <strong>Users</strong> page. If you are the only admin, or the key is lost with no backup: cPanel &rarr; <strong>Terminal</strong> &rarr; type <code>cd msp-control-panel</code>, then <code>php scripts/reset-2fa.php you@example.com</code> (your sign-in email). It prints a new secret: add it to your authenticator app by hand and delete the old entry. Your password does not change.</li>
    <li>Forgot your password and you are the only admin? Same Terminal, then <code>php scripts/reset-password.php you@example.com</code>. It asks for a new password; your 6-digit codes stay as they are.</li>
</ol>

<h2 id="backups">13. Backups</h2>
<p>Every business backs <strong>itself</strong> up, on its own server, with its own backup system (its <em>Backup management</em> page). This panel <strong>asks</strong> for those backups and shows what each business reports. Your clients' data never comes to this panel: only the date of the last backup, its size, whether it worked, and when someone last downloaded a copy.</p>
<ol>
    <li><strong>Every day by itself:</strong> the first admin to sign in each day starts the backup. You see "Today's backup has started".</li>
    <li><strong>One button:</strong> <strong>Backups</strong> &rarr; <strong>Back up everything now</strong>. Or <strong>Back up now</strong> on one business's row.</li>
    <li><strong>Every night, even when nobody signs in</strong> (recommended): cPanel &rarr; <strong>Cron Jobs</strong> &rarr; <em>Once Per Day</em> &rarr; command:<br>
        <code>cd /home/CPANELUSER/msp-control-panel &amp;&amp; php scripts/backup-all.php &gt;&gt; logs/backup-cron.log 2&gt;&amp;1</code><br>
        (change CPANELUSER to your cPanel user name). It does the same as the button.</li>
</ol>
<p>Each run also backs up this panel's own records. Those copies are encrypted with the signing key, kept outside the website folder, and the newest 14 are kept.</p>
<h3>Reading the Backups page</h3>
<ul>
    <li><strong>Backed up</strong>: the business's last good backup is less than 36 hours old.</li>
    <li><strong>Asked; waiting</strong>: the business backs up at its next check-in, straight away when it is online, otherwise within the hour. A business that already has a good backup from the last 6 hours does not make another one, so pressing the button twice does not fill its disk.</li>
    <li><strong>Last good backup is more than 36 hours old</strong> or <strong>Last backup failed</strong>: open that business's own <em>Backup management</em> page; it says what went wrong and what to do (often disk space).</li>
    <li><strong>Copy kept off-site</strong>: when someone last downloaded a backup from that business's <em>Backup management</em> page. A backup on the same server does not survive the server being lost: download one regularly (weekly) and keep it somewhere else, encrypted.</li>
    <li><strong>Nothing reported yet</strong>: the business has not checked in since this was added, or runs an older version of the system.</li>
</ul>
<p class="muted">Each business also has its own nightly backup. It is most reliable with its own cron job; see that system's backup guide.</p>

<h2 id="move-database">14. Moving this panel to its own database</h2>
<p>For now the panel's tables (all named <code>cp_...</code>) live in the Liwonde Sun database. Moving them to a database of their own takes about 10 minutes and nothing else changes. The complete checklist for moving to its own database <strong>and</strong> web address (including the email settings and the check script <code>php scripts/move-check.php</code>) is the file <strong>MOVING.md</strong> in the panel folder.</p>
<ol>
    <li>cPanel &rarr; <strong>MySQL Databases</strong>: create a new database and a new user with a strong password; add the user to the database with <strong>All Privileges</strong>. Note the three names.</li>
    <li>In this panel: <strong>Backups</strong> &rarr; Download the panel's database &rarr; <em>As it is right now</em>. Do this just before the move, so nothing recorded afterwards is lost. Open the file in a text editor and check its last line reads <code>-- complete: every table exported</code>; if not, download it again.</li>
    <li>cPanel &rarr; <strong>phpMyAdmin</strong> &rarr; click the <strong>new</strong> database &rarr; <strong>Import</strong> &rarr; choose the file &rarr; <strong>Go</strong>.</li>
    <li>cPanel &rarr; File Manager &rarr; <em>msp-control-panel/.env</em> &rarr; Edit: change <code>DB_NAME</code>, <code>DB_USER</code> and <code>DB_PASS</code> to the new ones &rarr; Save.</li>
    <li>Sign in to the panel and check your businesses, payments and users are there. Press <strong>Back up everything now</strong> once.</li>
    <li>Only when all is well, and a week later: in phpMyAdmin, in the <strong>Liwonde</strong> database, delete the tables that start with <code>cp_</code>. Never any other table.</li>
</ol>
<p><strong>Moving the panel to another server or domain too?</strong> Bring the whole <em>msp-control-panel</em> folder, including <em>keys/licence-private.pem</em> and <em>.env</em> (download them from the old server first). Without the signing key, the businesses would not trust the panel and your 6-digit codes would not work. Then follow 12 E for the new address.</p>

<h2 id="users">15. Users and passwords</h2>
<p>Everyone on the <strong>Users</strong> page can do everything in this panel. Each person signs in with their own email, password and 6-digit code.</p>
<h3>Add a user</h3>
<ol>
    <li><strong>Users</strong> &rarr; Add a user: their email (it is their sign-in name) and a temporary password of at least 12 characters, then your own password and a new code to confirm.</li>
    <li>Tell them the panel address, their email and the temporary password, by phone or in person (not in the same message as the address).</li>
    <li>They sign in with the code box empty, add the panel to their authenticator app, and choose their own password (section 9). Until then they show as <em>Waiting for first sign-in</em>.</li>
</ol>
<h3>Reset or remove a user</h3>
<ul>
    <li><strong>Reset</strong> (forgotten password or lost phone): give them a new temporary password. They are signed out everywhere and set up again at their next sign-in.</li>
    <li><strong>Remove</strong>: they can no longer sign in, and are signed out at once. What they did stays in the audit log. You cannot remove yourself or the last admin.</li>
    <li><strong>Change your own password</strong>: <strong>My account</strong>. Other browsers signed in as you are signed out.</li>
</ul>
<p class="muted">Every add, reset and removal needs your own password and a new code, and is written in the audit log. There is no "forgot password" email: ask another admin, or see 12 F.</p>

<h2 id="data-protection">16. Data protection</h2>
<p>What the panel does to keep your clients' data safe:</p>
<ul>
    <li><strong>Data stays with each client.</strong> Backups are made and kept on each business's own server. This panel only holds the facts needed to run the licence: business name, website address, plan, payments, and the dates and sizes of their backups.</li>
    <li><strong>Encrypted where it is stored.</strong> The panel's own backups and your 6-digit-code secrets are encrypted with the signing key. Passwords and licence keys are stored only as one-way hashes.</li>
    <li><strong>Only named people get in</strong>, each with a password and a code from their own phone. Downloads of any backup need the password and a new code again.</li>
    <li><strong>Everything is recorded</strong> in the audit log: sign-ins, users added or removed, every backup run and every download.</li>
    <li><strong>Limited keeping.</strong> The panel keeps 14 of its own backups; each business keeps its own on a fixed schedule (14 daily, 8 weekly, 12 monthly) and deletes older ones by itself.</li>
</ul>
<p>What only you can do (the law asks for these, not the software):</p>
<ul>
    <li>A written <strong>data processing agreement</strong> with each client: you look after their system and backups on their behalf.</li>
    <li>A short written <strong>retention policy</strong> (how long backups and records are kept) and a <strong>breach plan</strong> (who you tell, and how fast, if data is lost or exposed).</li>
    <li>Make sure each business keeps an <strong>off-site copy</strong> (Backups page, last column), stored encrypted, and that only named people can open it.</li>
</ul>
<?php
view_foot();
