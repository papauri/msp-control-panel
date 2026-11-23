# Hotel side of "Update from Remote" (Deployments page, panel 2026-11-23)

BUILT 2026-11-23: Liwonde 3fccfba, Rosalyn's 1bad20c (adds: no second pick-up while an earlier one is still running). The panel side is live code in
`app/deploy.php` (`deploy_answer_fields`, `deploy_clean_report`, `deploy_report_changes`) and `public/api/licence.php`.
No cPanel credentials anywhere (owner 2026-11-23: "no credentials: cron"). The hotel never pushes; it only pulls its own
branch with `git pull --ff-only` run by the account's cron (changed 2026-11-23 from cPanel's command-line UAPI: cron runs
inside CageFS on this host and uapi fails there with "Failed to execute /usr/local/cpanel/cpanel").

## 1. Licence answer (panel -> hotel, inside the SIGNED payload)
`deploy_requested`: `{"id": <int>, "at": <unix time>}` or `null`. Missing = old panel or no live copy set up: do nothing.
- `id` only rises (panel: `GREATEST(id + 1, unix time)`). The panel stops sending it once `done_id >= id`, or 24 hours
  after `at` (lapsed).
- Act only on an answer whose signature verified and that was issued in the last 2 hours (same rule as staff commands).
- Act only when `id > site_settings.licence_deploy_done_id` (and `> licence_deploy_seen_id` for a new pick-up).

## 2. `includes/licence.php` (check-in request, hotel -> panel)
Add an optional `deploy` object to the check-in JSON (keep the whole body under 4096 bytes):
```json
{"deploy": {"seen_id": 0, "done_id": 0, "ok": true, "msg": "text", "sha": "40 hex"}}
```
- `seen_id`: newest request id picked up (`site_settings.licence_deploy_seen_id`), `done_id`: newest finished
  (`licence_deploy_done_id`), `ok`/`msg`: the result of `done_id` (omit both when nothing has finished yet), `sha`: the
  commit checked out now, read from `.git` with files only (HEAD -> ref -> `refs/heads/*` or `packed-refs`; no `exec`).
- The panel accepts ids only when newer than what it has and not above its newest request; ints only; `ok` must be a
  JSON boolean; `msg` is cut to 500 printable characters and scrubbed; `sha` must be `^[0-9a-f]{40}$`. It is shown only.

## 3. New `scripts/deploy-check.php` at the hotel (CLI only: first line refuses non-CLI with 403)
- `--want`: read the stored licence answer (site_settings, signature already verified by `includes/licence.php`); if
  `deploy_requested.id` > `licence_deploy_seen_id` and > `licence_deploy_done_id` and the answer is < 2 h old and the
  request < 24 h old: set `licence_deploy_seen_id = id`, print one line, exit 0. Otherwise exit 1.
- `--report FILE`: if `licence_deploy_seen_id > licence_deploy_done_id` and FILE exists and its mtime is not older than the
  pick-up: parse git's output ending in a line `exit=N` (0 = done: "Already up to date." or "Updating a..b" + the "N files
  changed" line; other = failed with git's lines from the first `error:`/`fatal:`; git output with no `exit=` line = did
  not finish; the older uapi JSON `{"result": {"status": 1|0, "errors": [...]}}` is still read), set
  `licence_deploy_done_id = seen_id`, `licence_deploy_ok`, `licence_deploy_msg` (scrubbed, <= 500 chars), delete FILE.
  If picked up 15+ minutes ago and there is no result file: record a failure ("left no result"). Then trigger a normal
  licence check-in so the panel hears it now (it also goes with every later check-in).
- Exit 0 from `--report` unless something broke.

## 4. Cron line (cPanel > Cron Jobs, every 5 minutes; USER, folder and branch per site)
```
*/5 * * * * cd /home/USER/SITE && /usr/local/bin/php scripts/deploy-check.php --want && { GIT_TERMINAL_PROMPT=0 /usr/bin/git pull --ff-only origin main; echo "exit=$?"; } > storage/deploy-last.json 2>&1; /usr/local/bin/php scripts/deploy-check.php --report storage/deploy-last.json
```
- `&&` runs the pull only when `--want` exits 0; the `;` makes `--report` run every time (keeps the live sha current; a
  missing or older file is a no-op).
- The `{ ...; echo "exit=$?"; }` group stays inside the `&&` chain (idle runs write nothing) and puts git's exit code
  last in the file. The remote is public HTTPS (no credentials); `GIT_TERMINAL_PROMPT=0` makes git fail rather than
  wait for a password. Sites run straight from the repository (no .cpanel.yml), so this pull IS Update from Remote.
- Always pull the branch already checked out (here main): another branch would not fast-forward the live checkout.
- `/usr/local/bin/php`: cron's plain `php` may be php-cgi; the script's CLI guard then exits 1 (nothing pulled).
- A fast-forward-only pull: local changes on the server make it fail (reported with git's own message).
- The result file must sit outside the web root or in a folder the web server refuses (`storage/` at the hotels? check
  the hotel's `.htaccess`; otherwise use a dot-folder that is denied).

## 5. Tests (pure, hotel repo)
Fake answers only: `--want` decisions (new / seen / done / stale answer / lapsed), report parsing (ok, failed, noise,
empty), `.git` reading (loose, packed, detached, `..` refused), the `deploy` block shape and the 4096-byte limit.
