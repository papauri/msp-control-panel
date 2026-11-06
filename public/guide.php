<?php
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);

view_head('Guide');
?>
<h1>Licence panel guide</h1>
<p class="muted">How to use this panel. Only signed-in admins can see this page.</p>

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
    <li>After 5 wrong tries, the account is locked for 15 minutes.</li>
    <li>Your phone clock must be set to automatic. If the time is wrong, the 6-digit code will not work.</li>
</ul>
<p class="muted">If something goes wrong: wait 15 minutes, then sign in again. Ask another admin if you are still locked out.</p>

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
    <li>Back up the private key: download it once with cPanel File Manager from the path shown, and keep it offline. Never email it.</li>
</ol>
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
    <li>Click <strong>Import</strong> &rarr; <strong>Choose File</strong> &rarr; pick your newest panel backup file (see D) &rarr; <strong>Go</strong>.</li>
    <li>Open the panel and check your businesses are back.</li>
</ol>

<h3>D. Backups to keep (do these now, and after big changes)</h3>
<ol>
    <li><strong>Signing key</strong> (once): cPanel &rarr; File Manager &rarr; <em>msp-control-panel/keys</em> &rarr; right-click <strong>licence-private.pem</strong> &rarr; <strong>Download</strong>. Keep it on a USB stick or in your password manager. Never email it. <em>This is the one thing that cannot be made again.</em></li>
    <li><strong>Settings file</strong> (once): download <em>msp-control-panel/.env</em> the same way and keep it with the key.</li>
    <li><strong>Panel records</strong> (after recording payments): cPanel &rarr; <strong>phpMyAdmin</strong> &rarr; the panel's database &rarr; <strong>Export</strong> &rarr; <strong>Custom</strong> &rarr; under Tables, tick only the tables that start with <code>cp_</code> &rarr; <strong>Go</strong>. Keep the downloaded file with the key.</li>
</ol>

<h3>E. Moving this panel to a new web address</h3>
<ol>
    <li>cPanel &rarr; <strong>Domains</strong> &rarr; create the new address and point its folder (Document Root) at the <em>msp-control-panel</em> folder.</li>
    <li>For each business: cPanel &rarr; File Manager &rarr; that business's <strong>.env</strong> &rarr; Edit &rarr; change the <code>LICENCE_URL=</code> line to the new address, ending in <code>/api/licence.php</code> &rarr; Save.</li>
    <li>Open each business's <strong>Licence</strong> page and press <strong>Check now</strong> to confirm it connects.</li>
</ol>
<?php
view_foot();
