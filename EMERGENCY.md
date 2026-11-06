# If something goes wrong (no programmer needed)

Everything here is done in **cPanel** only. This is the same as section 12 of the panel's **Guide** page,
kept here so you can read it even when the panel does not open.

## First: nobody is locked out straight away

- If the panel stops working, every business keeps using the last answer it got. Nothing changes for **35 days**.
- After that, each business follows its own **Paid until** date. A business that has paid ahead keeps working.
- So you have weeks, not minutes. Do not rush.

## A. Switch the licence OFF for one business (about 1 minute)

Use this if a business is paused by mistake, has paid but you cannot record it, or the panel is down for a long time.

1. Log in to **cPanel** and open **File Manager**.
2. Click **Settings** (top right), tick **Show Hidden Files (dotfiles)**, click **Save**.
3. Open that business's website folder. (Not sure which? cPanel → **Git Version Control** shows it as the *Repository Path*.)
4. Right-click the file **.env** → **Edit** → **Edit** again.
5. Find the line that starts with `LICENCE_TENANT=`.
6. Type a `#` at the very start of that line, so it reads `#LICENCE_TENANT=...`
7. Click **Save Changes**.

That's it. The business works normally straight away: no banner, no locks, no checks. Its data is not touched.

**To switch it back ON later:** do the same steps and delete the `#`. Then open the panel and check the business shows the right status.

**All businesses?** Do step A for each one.

## B. The panel shows an error or a blank page

1. cPanel → **Git Version Control** → **Manage** next to *msp-control-panel* → **Pull or Deploy** tab → **Update from Remote**. Try the panel again.
2. Still broken? cPanel → **File Manager** (hidden files shown) → open the *msp-control-panel* folder. Check these are there:
   - **.env** (the settings file). If it is missing, upload your saved copy.
   - **keys/licence-private.pem** (the signing key). If it is missing, upload your offline backup into the *keys* folder.
     **Do not** click "Create signing key" again: a new key would not match the businesses.
3. Page says it cannot reach the database? See C.

## C. The panel's records are lost (database)

1. cPanel → **phpMyAdmin** → click the database the panel uses (its name is on the `DB_NAME` line of the panel's .env).
2. Click **Import** → **Choose File** → pick your newest panel backup file (see D) → **Go**.
3. Open the panel and check your businesses are back.

## D. Backups to keep (do these now, and after big changes)

1. **Signing key** (once): cPanel → File Manager → *msp-control-panel/keys* → right-click **licence-private.pem** → **Download**.
   Keep it on a USB stick or in your password manager. Never email it. *This is the one thing that cannot be made again.*
2. **Settings file** (once): download *msp-control-panel/.env* the same way and keep it with the key.
3. **Panel records** (after recording payments): cPanel → **phpMyAdmin** → the panel's database → **Export** → **Custom** →
   under Tables, tick only the tables that start with `cp_` → **Go**. Keep the downloaded file with the key.

## E. Moving the panel to a new web address

1. cPanel → **Domains** → create the new address and point its folder (Document Root) at the *msp-control-panel* folder.
2. For each business: cPanel → File Manager → that business's **.env** → Edit → change the `LICENCE_URL=` line to the
   new address, ending in `/api/licence.php` → Save.
3. Open each business's **Licence** page and press **Check now** to confirm it connects.
