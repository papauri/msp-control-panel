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
     The key also protects your sign-in codes (see F): without it, your 6-digit code is not accepted.
3. Page says it cannot reach the database? See C.

## C. The panel's records are lost (database)

1. cPanel → **phpMyAdmin** → click the database the panel uses (its name is on the `DB_NAME` line of the panel's .env).
2. Click **Import** → **Choose File** → pick your newest panel backup file (see D) → **Go**.
3. Open the panel and check your businesses are back.

## D. Backups to keep (do these now, and after big changes)

1. **Signing key and settings** (once, and again after changing the panel's settings): in the panel open **Signing key**,
   enter your password and a new 6-digit code, click **Download backup**. One file holds the private key, the public key
   and the panel's settings file (.env). Keep it on a USB stick or in your password manager. Never email it.
   *The key is the one thing that cannot be made again.*
2. **If the panel will not open:** cPanel → File Manager → *msp-control-panel/keys* → right-click **licence-private.pem** →
   **Download**, and download *msp-control-panel/.env* the same way.
3. **Panel records** (after recording payments): in the panel open **Backups** → Download the panel's database → *As it is
   right now*, with your password and a new code. Keep the file with the key, offline. If the panel will not open:
   cPanel → **phpMyAdmin** → the panel's database → **Export** → **Custom** → tick only the tables that start with `cp_` → **Go**.

## E. Moving the panel to a new web address

1. cPanel → **Domains** → create the new address and point its folder (Document Root) at the *msp-control-panel* folder.
2. For each business: cPanel → File Manager → that business's **.env** → Edit → change the `LICENCE_URL=` line to the
   new address, ending in `/api/licence.php` → Save.
3. Open each business's **Licence** page and press **Check now** to confirm it connects.

## F. Your 6-digit code is not accepted

1. Check your phone's clock is set to automatic, wait for a new code, and try once more.
2. Tried 5 times? Wait 15 minutes, or sign in from another network (for example your phone's mobile data). Wrong tries
   block only the place they came from; your account itself is never locked.
3. Still not working: check *msp-control-panel/keys/licence-private.pem* is there (see B). The secret behind your codes
   is stored encrypted with that key; put it back from your offline backup if it is missing.
4. Lost the phone? Another admin can reset you on the **Users** page. The only admin, or lost the key with no backup?
   cPanel → **Terminal** → type `cd msp-control-panel`, then
   `php scripts/reset-2fa.php you@example.com` (your sign-in email). It prints a new secret: add it to your
   authenticator app by hand and delete the old entry. Your password does not change.
5. Forgot your password and you are the only admin? Same Terminal, then `php scripts/reset-password.php you@example.com`.
   It asks for a new password; your 6-digit codes stay as they are.

## G. Moving the panel to its own database (it shares the Liwonde database for now)

1. cPanel → **MySQL Databases**: create a new database and user (strong password); add the user with **All Privileges**.
2. In the panel: **Backups** → Download the panel's database → *As it is right now* (just before the move).
3. cPanel → **phpMyAdmin** → the **new** database → **Import** → choose the file → **Go**.
4. File Manager → *msp-control-panel/.env* → Edit: change `DB_NAME`, `DB_USER`, `DB_PASS` to the new ones → Save.
5. Sign in and check businesses, payments and users are there. Press **Back up everything now** once.
6. Only when all is well, a week later: phpMyAdmin → the **Liwonde** database → delete only the tables starting with `cp_`.

Moving to another server or domain as well? Take the whole *msp-control-panel* folder including *keys/licence-private.pem*
and *.env*: without the signing key the businesses would not trust the panel and the 6-digit codes would not work. Then E.
