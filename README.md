# PTO Tracker

Replacement for the two Google Sheet + Apps Script time-off trackers
("LSP Calendar Update" for the US office, "BuyLSP Calendar" for the Manila artists).
One plain-PHP 8 + MySQL app, hosted at `https://pto.lightsaberpromotions.com/` on the
existing Liquid Web cPanel account.

- `SPEC.md` is the authoritative build specification (data model, rules per group, screens,
  viewer-page password and trust cookie, import, local development).
- `RECOMMENDATION.md` is the design reasoning: why this stack, Google Calendar sync design,
  cutover plan, cPanel deployment steps, build milestones. Where it differs from `SPEC.md`,
  `SPEC.md` wins.

## Layout (mirrors the server's home directory)

- `public_html/pto/` - the web root of the subdomain. The only web-served folder.
- `pto_app/` - the code: `lib/`, `migrations/`, `tools/`, `tests/`. Not web-served. Its own
  `README.md` explains how to run locally, run the tests and reset the local database.
- `pto_data/` - config, logs, backups, sessions. Private on the server.
- `reference/` - the source material, never deployed: the two workbooks as downloaded on
  2026-09-14, their Apps Script sources, JSON dumps of every sheet, the Python oracles that
  reproduce the sheets' balances, the current viewer pages' calendar lists, and the notes from
  the design workflows.

## Running locally

1. Start XAMPP's MySQL (control panel, or `C:\xampp\mysql\bin\mysqld.exe --standalone`).
2. `C:\xampp\php\php.exe pto_app\tools\dev_reset.php` - creates `pto_data\config.php` if missing,
   rebuilds the `pto_local` database from `pto_app\migrations\001_init.sql`, creates the local admin
   and imports both fixture snapshots.
3. `run-local.bat` - serves `public_html\pto` on http://127.0.0.1:8020/.
4. `C:\xampp\php\php.exe pto_app\tests\balance_test.php` - must print one green line before any upload.

Local logins after a reset: admin `chris@lightsaberpromotions.com` / `changeme-now`;
viewer pages (`view.php?g=us`, `view.php?g=manila`) password `staff`.
