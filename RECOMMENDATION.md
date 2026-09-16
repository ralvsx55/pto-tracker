# LSP Time Off: Recommendation for Chris

Prepared 2026-09-14 for Chris Coleman, Lightsaber Promotions. Everything below was checked against the real workbook (15 employees, 177 time-off rows), the bound Apps Script (`appsscript_Code.gs`), and the research on Google Calendar and cPanel. Where a technical claim depends on research, its confidence level is noted in brackets: **[verified]** means it was confirmed in Google's or cPanel's own documentation, **[likely]** means it comes from reputable practitioner sources, **[unverified]** means it is a reasonable assumption you should confirm on day one.

**Revision 3 (2026-09-14, evening).** Chris answered the remaining questions and added a second group. Decisions: the company Google account is a plain Gmail (so none of the Workspace prerequisites apply); company holidays no longer count against anyone's PTO (a rule change, applied from an effective date so history is untouched); the viewer pages get a shared password with a long-lived trust cookie; the subdomain is `pto.lightsaberpromotions.com`; past birthdays go at cutover. The Manila artists (Bright Bird Design, PTO only, their own sheet, script, calendars and viewer page) join the same app as a second **group** with its own policy. **`SPEC.md` is now the authoritative build specification and supersedes this document wherever they differ**; this document remains the reasoning behind the design.

**Revision 2 (2026-09-14, same day).** Updated after Chris confirmed: the five calendars belong to a company Google account; the only consumer of the `doGet` JSON is the staff viewer page at `https://lightsaberpromotions.com/timeoff518652351` (an HTML page with the Google Calendar embed plus a remaining-days table, bookmarked by every employee); departed employees should be easy to remove, with their PTO and birthdays taken off the calendars but their PTO history archived; end-of-month sales and factory closings must stay postable; and the app will live on a subdomain of lightsaberpromotions.com. The changes: the viewer page becomes part of the app and the `doGet` passthrough is gone; departure now removes calendar events instead of keeping them; the sales calendar stays active; deployment targets a subdomain. A verification pass on that revision then cut the JSON API and the rehire-linking feature, made the birthday calendar regenerable, added an environment guard for laptop copies and orphan handling after a restore, and found that this account's `public_html/.htaccess` already redirects everything to the WordPress site, which shapes the subdomain setup and the bookmark redirect (sections 3 and 10).

---

## 1. Recommendation in three sentences

Build a small plain-PHP 8 + MySQL application (no framework, no Composer, no SSH) on a subdomain such as `pto.lightsaberpromotions.com` on your existing Liquid Web cPanel account, holding employees, time-off requests, adjustments and company events in a real database, recomputing every balance from first principles using a line-for-line port of the six Apps Script functions, proven identical by a test that replays all 177 rows and the 15 current balances, and serving the staff viewer page (the same Google Calendar embed plus the remaining-days table) directly from that database so the bookmarked page keeps working with nothing fetched from Google Apps Script. Keep the five existing Google Calendars exactly as they are (same IDs, same event titles, same all-day events) and make them a read-only mirror of the database, written through the Calendar REST API with a Google service account that each calendar is shared with, so nobody in the office changes anything on their phone. Cut over by disabling the Apps Script triggers first, adopting the events that already exist on the calendars instead of recreating them, and redirecting the old bookmarked URL to the new viewer page.

---

## 2. Why not the alternatives

**A leave-tracking SaaS (Timetastic, Calamari, Vacation Tracker, LeaveBoard, BambooHR, Gusto).** Two of them can actually do your hire-anniversary cycles: Timetastic Business (~$270/yr for 15 seats, per-employee leave years, but the 0/5/10/15 tenure tiers are a manual allowance edit) and Calamari Time Off (~$360/yr, anniversary accrual with seniority rules, more setup). LeaveBoard is cheaper but resets on a fiscal year only, which fails your hard rule; Vacation Tracker's $100/month minimum makes it ~$1,200/yr; BambooHR and Gusto are payroll suites at 5-10x the cost. None of them cover the birthday, holiday or factory-closing calendars, and most exclude public holidays from consumed days by default (your rule does not), so that has to be configured deliberately in whichever one you pick. On the calendar side, Timetastic and the open-source options rely on webcal feeds that Google refreshes only every 12-24 hours with no manual refresh **[likely]**; Calamari and Vacation Tracker write to Google Calendar directly, but cost $360-1,200/yr and still leave the four event calendars to be maintained by hand. You asked for something you host with a real database; SaaS gives you a subscription and someone else's data model. Dismissed.

**Stay in Google (Apps Script HtmlService web app, or AppSheet).** This is the cheapest path ($0, no server) and the current logic already lives there. It is honestly the right answer if the only pain were the spreadsheet UI. But it keeps the Sheet as the database, keeps a sync routine that (as it turns out, see section 7) deletes and recreates every PTO and event calendar entry on every run, keeps you writing Apps Script rather than the PHP you already maintain, and gives you no per-row audit log tied to a user and no departed-employee handling; restore is whole-sheet version rollback rather than per-request. You explicitly asked for a real database on your own cPanel. Dismissed, with the note that the existing Apps Script survives as a fallback bridge (section 7) if Google Cloud console work turns out to be blocked.

**Open-source HR packages (OrangeHRM, IceHrm, Jorani, Who's OOO, TimeOff.Management).** OrangeHRM Starter has one company-wide leave period; IceHrm is Docker-first with conflicting claims about whether leave is even in the free edition; Who's OOO states it has no accrual rules and needs PHP 8.5; TimeOff.Management is Node.js. Jorani is the only one that installs on cPanel (it is in Softaculous) and can express per-employee dated entitlements, but it is built around an approval workflow you do not have and needs a manual entitlement row per employee per cycle. Your rule set is about 150 lines of code; adopting a 50,000-line HR package to run them is the wrong trade. Dismissed.

**Heavier PHP frameworks (Laravel, Symfony, Slim) or Node/Python.** cPanel removed the bundled Composer in version 130 (July 2025) **[verified]**, so any Composer-based framework means building `vendor/` on your Windows machine and uploading 20-50 MB through File Manager, plus (Laravel) remapping the document root and running migrations from a terminal. Node and Python on cPanel both require root-side Passenger setup (mod-alt-passenger or Application Manager) before you can even start **[verified]**. Your Liquid Web account is almost certainly a managed VPS where you or support could do that **[verified]**, but it is exactly the server-side setup you said you want to avoid, and it is a treadmill for one part-time maintainer. Dismissed. The same logic rules out `google/apiclient` (47.7 MB bundled, requires PHP 8.1+, Guzzle, Monolog) in favour of a ~100-line hand-rolled JWT-plus-curl client **[likely]**.

---

## 3. Architecture overview

**Runtime.** The newest PHP 8.x that cPanel MultiPHP Manager offers for the domain, and at least ea-php83: PHP 8.2's security support ends 2026-12-31, so choosing it in September would mean a version bump within three months of go-live. The stock EasyApache 4 profile ships ea-php81/82; ea-php83/84/85 are packaged and a WHM click (or a one-line support ticket) away **[verified]**. Required extensions are all in the default profile: `pdo_mysql` (bundled with mysqlnd), `curl`, `openssl`, `json`, `mbstring`, `session` **[verified]**. Code is written to the 8.2-through-8.5 subset (`declare(strict_types=1)`, `DateTimeImmutable`, PDO, no dynamic properties) so yearly PHP bumps are a non-event.

**Framework.** None. One PHP file per screen, the way you already write tools. No router, no `.htaccess` rewrites, so nothing depends on Apache module quirks. A shared `lib/bootstrap.php` handles timezone, session, PDO, CSRF, auth and the error handler; `lib/layout.php` renders header, nav and footer. Total around 2,000 lines of PHP, one hand-written CSS file, one small vanilla JS file. No CDN links, no build step, nothing external that can disappear.

**Timezone, in code, not in php.ini.** `bootstrap.php` calls `date_default_timezone_set('America/New_York')` unconditionally and computes `$today = new DateTimeImmutable('today')` once; every screen and the cron scripts take `$today` from there. This matters because `date.timezone` set through MultiPHP INI Editor applies to the web handler only, not to the CLI binary the cron jobs use, and a 03:05 server-time run on a UTC box would otherwise compute "today" and the birthday year for the previous day. For the same reason every `created_at` / `updated_at` is written from PHP, never with MySQL `NOW()`.

**Database.** The MySQL 8 / MariaDB 10.x that cPanel already runs, created in the MySQL Database Wizard, inspected in the phpMyAdmin that is already there, included in your existing account backups. All DDL uses only the common subset (InnoDB, utf8mb4, `DATE` for every business date, `MEDIUMTEXT` for JSON blobs, no `JSON` column type, no generated columns) so XAMPP's MariaDB and the server's engine behave identically.

**Google Calendar.** Raw Calendar API v3 over curl, authenticated with a service-account JWT signed by `openssl_sign` (about 180 lines in `lib/calendar.php`). Details in section 7.

**File layout.** The app lives on its own subdomain, `pto.lightsaberpromotions.com` (pick any name you like; `pto` is the name Chris chose). cPanel creates the subdomain with a document root under `public_html` by default (`/home/CPUSER/public_html/pto`), which is exactly what the WHM tweak "Restrict document roots to public_html" allows, so no WHM change is needed; keep the default rather than typing a path outside `public_html`. Three sibling folders: one web-served, one disposable (code, replaced wholesale on every update), one precious (config, key, backups, logs, never touched by updates).

```
/home/CPUSER/public_html/pto/      the ONLY web-served folder (document root of pto.lightsaberpromotions.com)
    view.php                            the staff viewer page: calendar embed + remaining-days table, no login
    index.php  login.php  logout.php  requests.php  request.php
    employees.php  employee.php  events.php  history.php  admin.php
    preview.php  setup.php
    .htaccess                           one line, RewriteEngine On (see below)
    assets/app.css  assets/app.js
/home/CPUSER/pto_app/                   code, not web-served
    lib/        bootstrap, db, auth, csrf, balance, calendar, sync, audit, layout, helpers
    migrations/ 001_init.sql, 002_....sql (forward-only, additive; applied by pasting into phpMyAdmin)
    cron/       sync.php  nightly.php
    tools/      import_sheet.php  adopt_existing_events.php  reset_sync_state.php  verify.php  balance_oracle.py
    tests/      balance_test.php  fixtures/sheet_snapshot_2026-09-14.json
    README.md   the runbook: every step in sections 9 and 10, verbatim, plus "Calendar sync stopped"
/home/CPUSER/pto_data/                  private (700)
    config.php  google-service-account.json (600)  my.cnf (600, for mysqldump)
    backups/  logs/  cache/  sessions/
```

Every file in `public_html/pto/` starts with one line, `require dirname(__DIR__, 2).'/pto_app/lib/bootstrap.php';`, and `bootstrap.php` defines `PTO_DATA` as `dirname(__DIR__, 2).'/pto_data'` at its top (one constant to change if a host ever lays things out differently). Locally on XAMPP the same three folders sit as `C:\xampp\htdocs\timeoff`, `C:\xampp\pto_app` and `C:\xampp\pto_data`; the two `dirname(__DIR__, 2)` expressions resolve to `C:\xampp` there and `/home/CPUSER` on the server, so nothing under `htdocs` is web-served except the public folder. One thing to know about that folder: it sits inside `public_html`, and `public_html/.htaccess` on this account already contains a catch-all rule (checked on 2026-09-14: every URL on `lightsaberpromotions.com` answers with a 301 to `lsp.lightsaberpromotions.com`, except URLs starting with `timeoff518652351`, which are served from the static viewer page). Apache applies a parent folder's `.htaccess` to its subfolders, so without protection the subdomain would inherit that catch-all and bounce to the WordPress site. The fix is the one-line `.htaccess` in the app folder, `RewriteEngine On`: a folder that declares its own rewrite engine does not inherit the parent's rewrite rules **[verified]**. It ships in `pto_public.zip`, and the setup page checks it is there. `bootstrap.php` additionally checks the `Host` header and redirects anything that is not the subdomain to `base_url`, so there is only ever one address to bookmark.

**How a request flows.** Browser hits `https://pto.lightsaberpromotions.com/request.php`. `bootstrap.php` sets the timezone, starts the session, checks login and role, verifies the CSRF token on POST, opens PDO. (The viewer page `view.php` is the one exception to the login check: it opens PDO, computes the same balances, renders the table and the calendar embed, and never writes.) The page handler validates input, opens a transaction, writes the row plus its `audit_log` entry (same transaction, so they cannot be separated), commits, then marks the affected calendar dirty and attempts an inline sync with a 10-second curl budget. The confirmation says "Saved. Calendar updated.", "Saved. Calendar will update within 15 minutes." or, whenever `sync_mode` is not `live`, "Saved. Calendar sync is in dry-run mode." Every balance shown anywhere (dashboard, ledger, viewer page, form preview) is computed on the fly by `lib/balance.php` from `employees + time_off + adjustments`; nothing computed is ever stored. A cron job every 15 minutes syncs whatever is still dirty; a nightly job reconciles all five calendars, refreshes birthday rows, runs the self-test, writes the JSON export, trims old logs and backups, and emails you only if something is wrong; a third cron line runs `mysqldump`.

---

## 4. Data model

MySQL 8 / MariaDB 10.x, InnoDB, utf8mb4. `CHECK` constraints are enforced on MariaDB 10.2+ and MySQL 8.0.16+; on anything older they are parsed and ignored, and the application validates the same rules anyway. Nine small tables; calendar sync state is two columns on each mirrored row rather than a separate ledger.

```sql
-- Employees. Balances and cycles are NEVER stored; they are recomputed from
-- employees + time_off + adjustments on every read (lib/balance.php).
-- Allotment tiers live in code, not in a table: they change once a decade
-- and a table invites accidental edits.
CREATE TABLE employees (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  last_name     VARCHAR(60)  NOT NULL,
  first_name    VARCHAR(60)  NOT NULL,       -- display/calendar name = CONCAT(last_name, ', ', first_name)
                                             -- => "Walker, Rebecca - Vacation", byte-identical to today
  hire_date     DATE         NOT NULL,       -- anniversary anchor for the 12-month cycle
  birth_month   TINYINT UNSIGNED NULL,       -- 1-12
  birth_day     TINYINT UNSIGNED NULL,       -- 1-31
  birth_year    SMALLINT UNSIGNED NULL,      -- NULL when the sheet held a placeholder (>= 2023); never shown as an age
  status        ENUM('active','departed') NOT NULL DEFAULT 'active',
  departed_on   DATE NULL,                   -- required when status='departed'. History is KEPT in the database
                                             -- (Former employees archive); the person disappears from the viewer
                                             -- page, pickers and dashboard, and ALL of their calendar events
                                             -- (every PTO/Vacation event and every birthday) are removed
  notes         VARCHAR(255) NULL,
  legacy_row    INT UNSIGNED NULL,           -- sheet row number at import
  created_at    DATETIME NOT NULL, created_by INT UNSIGNED NULL,   -- written by PHP in America/New_York, never NOW()
  updated_at    DATETIME NOT NULL, updated_by INT UNSIGNED NULL,
  KEY ix_emp_name (last_name, first_name),   -- deliberately NOT unique: a rehire is a new row with the same name
                                             -- and a new hire date; the form warns on a duplicate active name
  CONSTRAINT ck_emp_departed CHECK (status = 'active' OR departed_on IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The "Time Requested Off" sheet minus its calculated columns E/F/G.
-- Deleting a request is a hard delete plus a full before-image in audit_log;
-- History > Restore re-inserts from that snapshot.
CREATE TABLE time_off (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT UNSIGNED NOT NULL,
  kind          ENUM('PTO','Vacation') NOT NULL,   -- canonical casing; 'vacation' rows normalized on import
  start_date    DATE NOT NULL,
  end_date      DATE NOT NULL,                     -- INCLUSIVE. Working days = Mon-Fri in [start,end];
                                                   -- holidays NOT excluded; computed, never stored
  note          VARCHAR(255) NULL,
  legacy_row    INT UNSIGNED NULL,
  google_event_id    VARCHAR(120) NULL,            -- Google-generated id of the mirrored event; NULL = not yet pushed
  synced_fingerprint CHAR(40) NULL,                -- sha1(title|start|end_exclusive) as last pushed
  sync_error         VARCHAR(300) NULL,            -- last API error for this row, cleared on success
  created_at    DATETIME NOT NULL, created_by INT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL, updated_by INT UNSIGNED NULL,
  CONSTRAINT fk_to_emp FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT ck_to_range CHECK (end_date >= start_date),
  KEY ix_to_emp_start (employee_id, start_date, id),   -- (start_date, id) is the engine's sort order
  KEY ix_to_start (start_date),
  KEY ix_to_gid (google_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Deliberately NO unique key on (employee, start, end): the sheet allowed duplicates and a
-- weekend-only row exists. The form warns; it does not block.

-- The "PTO Adjustments" sheet the script supports but nobody ever created. Same semantics.
CREATE TABLE adjustments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id    INT UNSIGNED NOT NULL,
  kind           ENUM('PTO','Vacation') NOT NULL,
  effective_date DATE NOT NULL,                    -- selects the cycle: cycle_start <= effective_date < cycle_end
  days           DECIMAL(5,2) NOT NULL,            -- + grant, - deduct; fractional allowed (script used parseFloat)
  reason         VARCHAR(255) NOT NULL,            -- mandatory: an adjustment without a reason is not auditable
  created_at     DATETIME NOT NULL, created_by INT UNSIGNED NULL,
  CONSTRAINT fk_adj_emp FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT ck_adj_days CHECK (days <> 0),
  KEY ix_adj_emp (employee_id, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The five Google Calendars. Seeded by setup.php with the IDs from appsscript_Code.gs.
CREATE TABLE calendars (
  cal_key       VARCHAR(20) NOT NULL PRIMARY KEY,   -- 'pto','birthdays','holidays','factory','sales'
  label         VARCHAR(60) NOT NULL,
  kind          ENUM('pto','birthdays','events') NOT NULL,
  google_calendar_id VARCHAR(160) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,      -- 0 hides it from navigation and the viewer page
  sync_enabled  TINYINT(1) NOT NULL DEFAULT 1,      -- 0 freezes the Google side without deleting anything
  dirty         TINYINT(1) NOT NULL DEFAULT 0,      -- set by every write that touches it; cleared by a good sync
  last_sync_at  DATETIME NULL, last_sync_ok TINYINT(1) NULL, last_sync_message VARCHAR(300) NULL,
  sort_order    TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO calendars (cal_key, label, kind, google_calendar_id, is_active, sync_enabled, sort_order) VALUES
 ('pto',       'PTO / Vacation',            'pto',       '0ec358900fdb35e7d48a00c7930be8eff9a8599d9cc6f1a47a13de9b044f34a9@group.calendar.google.com', 1, 1, 1),
 ('birthdays', 'Birthdays',                 'birthdays', '8579146e437a950aaa8b704aab72515388e5dd18eaa7d086167f02810f776bd8@group.calendar.google.com', 1, 1, 2),
 ('holidays',  'Company Events & Holidays', 'events',    '4df8b9e4839d04a9f35bc520a86e85cc3b8856d8df185f2a0aef8cf69007f63c@group.calendar.google.com', 1, 1, 3),
 ('factory',   'Factory Closings',          'events',    '470bb84a2fadbdb4c543cc0676dc1e0e5bd413cf7ff41b39eb80b0333909db56@group.calendar.google.com', 1, 1, 4),
 ('sales',     'End of Month Sales',        'events',    'e96813af5476467dd32b9eab64982306f0409967ec4610be387aabdcdc899a70@group.calendar.google.com', 1, 1, 5);
-- All five stay active: sales and factory closings are still posted, and all five (plus Google's
-- public US Holidays calendar) are what the staff viewer page embeds.

-- The three [Event Name, Start, End] sheets collapse into one table with a category.
CREATE TABLE events (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cal_key       VARCHAR(20) NOT NULL,              -- the category: holidays / factory / sales
  title         VARCHAR(200) NOT NULL,             -- trimmed on save
  start_date    DATE NOT NULL,
  end_date      DATE NOT NULL,                     -- INCLUSIVE; Google receives end + 1 day
  legacy_row    INT UNSIGNED NULL,
  google_event_id VARCHAR(120) NULL, synced_fingerprint CHAR(40) NULL, sync_error VARCHAR(300) NULL,
  created_at    DATETIME NOT NULL, created_by INT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL, updated_by INT UNSIGNED NULL,
  CONSTRAINT fk_ev_cal FOREIGN KEY (cal_key) REFERENCES calendars(cal_key),
  CONSTRAINT ck_ev_range CHECK (end_date >= start_date),
  KEY ix_ev_cal_start (cal_key, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per (employee, year) for the current year and the next, active employees only. The
-- nightly job and every employee save top these up; marking someone departed deletes their rows
-- and their Google events. The whole birthday calendar is regenerable from this table (section 7).
CREATE TABLE birthday_events (
  employee_id     INT UNSIGNED NOT NULL,
  year            SMALLINT UNSIGNED NOT NULL,
  google_event_id VARCHAR(120) NULL, synced_fingerprint CHAR(40) NULL, sync_error VARCHAR(300) NULL,
  PRIMARY KEY (employee_id, year),
  CONSTRAINT fk_bd_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(120) NOT NULL UNIQUE,      -- the login name; any address works, it is not tied to Google
  display_name  VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,             -- password_hash(..., PASSWORD_DEFAULT)
  role          ENUM('admin','editor') NOT NULL DEFAULT 'editor',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only. The app has no code path that updates or deletes here. Kept forever; it is small.
CREATE TABLE audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  at            DATETIME NOT NULL,
  user_id       INT UNSIGNED NULL,                 -- NULL = import or cron
  ip            VARCHAR(45) NULL,
  action        ENUM('insert','update','delete','restore','login','login_failed','import','sync','setting') NOT NULL,
  table_name    VARCHAR(30) NULL,
  row_id        INT UNSIGNED NULL,
  employee_id   INT UNSIGNED NULL,                 -- denormalized so "history of this employee" is one indexed query
  before_json   MEDIUMTEXT NULL,                   -- full row before (TEXT, not JSON type, for MariaDB/MySQL parity)
  after_json    MEDIUMTEXT NULL,
  summary       VARCHAR(255) NOT NULL,             -- 'Walker, Rebecca: Vacation 2026-10-11..2026-10-13 (3 days) added'
                                                   -- the name as it was at the time; a later rename does not rewrite history
  KEY ix_audit_emp (employee_id, at), KEY ix_audit_at (at), KEY ix_audit_row (table_name, row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tiny key/value: schema_version, engine_version, google_token_cache,
-- google_token_expires_at, sync_mode ('dry_run'|'live'), consecutive_sync_failures, last_alert_sent_at.
CREATE TABLE settings (
  name  VARCHAR(40) NOT NULL PRIMARY KEY,
  value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Migrations are numbered files in `migrations/`, each ending with `UPDATE settings SET value='N' WHERE name='schema_version'`; you apply one by pasting it into phpMyAdmin > SQL. The footer shows the schema version the database reports against the one the code expects and a yellow bar if they differ; there is no in-app runner. Migrations are additive only (add tables and columns, never drop) so an older code zip still runs against a newer schema, which is what makes rollback a matter of re-extracting the previous zip.

---

## 5. Balance engine

**Where.** `lib/balance.php`: pure functions, no database access, no clock. Input: one employee (hire date), that employee's `time_off` rows, that employee's `adjustments`, and an explicit `$today` (which `bootstrap.php` computes in America/New_York). Output: arrays. Every screen, the viewer page, `preview.php`, the importer and the self-test call the same functions; nothing else in the codebase does cycle arithmetic. A constant `ENGINE_VERSION = '1.0.0-sheet'` is stamped into the nightly JSON export so a future rule change is visibly versioned. Keep the Apps Script function names so the two can be diffed side by side.

**The rules, restated as a spec** (H = hire date, all dates are calendar dates with no time-of-day):

1. **Cycle for a reference date D.** `A = date(D.year, H.month, H.day)`. If `A <= D` then `cycle_start = A`, else `cycle_start = date(D.year - 1, H.month, H.day)`. `cycle_end = date(cycle_start.year + 1, H.month, H.day)`, exclusive. Construct dates the way JavaScript's `new Date(y, m, d)` does: Feb 29 in a non-leap year rolls to Mar 1 (PHP `DateTimeImmutable::setDate` does the same). A request starting exactly on the anniversary belongs to the new cycle.
2. **Years of service at a date X.** `X.year - H.year`, minus 1 if `(X.month, X.day) < (H.month, H.day)`. Not clamped at zero.
3. **Allotment for a cycle**, using years of service at `cycle_start`: Vacation = 15 if >= 10, 10 if >= 5, 5 if >= 1, else 0. PTO = 3 if >= 1, else 0. New hires get nothing until their first anniversary. The employee sheet's "Combined Total" column is Vacation + PTO and is shown as "Total N" wherever the two allotments appear.
4. **Adjustments.** For each kind, add the sum of `days` for adjustments whose `effective_date` satisfies `cycle_start <= effective_date < cycle_end`. Fractional allowed.
5. **Days consumed by a request.** Count calendar days in `[start_date, end_date]` inclusive whose ISO weekday is 1-5. Company holidays are NOT excluded. No half days.
6. **Cycle membership.** A request belongs to the cycle containing its `start_date` only; a range that straddles an anniversary is charged entirely to the first cycle.
7. **Running balance (the sheet's E/F columns).** Sort the employee's requests by `start_date`, then `id` (id preserves sheet row order after import, which reproduces the script's row-index tie-break). Walk them; whenever the cycle changes, reset used totals to zero and load that cycle's allotment (rule 3 + rule 4). After each request, `remaining = allotment - used` for that kind. Remaining values may be negative.
8. **Next-cycle note (the sheet's G column).** If the request's `cycle_start` equals `today's cycle_start + 1 year`, attach `After MM/DD/YYYY: N PTO / N Vac` using that request's running remaining values.
9. **Current and next cycle totals (dashboard, API).** For offset 0 and 1: `cycle_start = today's cycle_start + offset years`; allotment per rules 3-4; used = sum of rule-5 days over requests whose `start_date` falls in that cycle; remaining = allotment - used. This is `computeCycleRemaining`.
10. **No carry-over, no caps, no approval.** Balances are never clamped and never stored.

**Edge cases, and what the engine does with each:**

| Case | Behaviour (matches the sheet) | Test |
|---|---|---|
| Saturday-only row (Danalewich 2026-05-16) | 0 working days; balance unchanged; row still shown and mirrored to the calendar | fixture row |
| 6-working-day request (Mon-Mon) | counts 6 | fixture row |
| Request starting on the anniversary date | new cycle | hand-written |
| Request starting the day before | old cycle | hand-written |
| Range straddling an anniversary | all days charged to the first cycle; form warns and offers to split into two rows | hand-written |
| Request before hire date, or employee under 1 year | allotment 0, balance goes negative, no crash | hand-written |
| Negative balances | allowed, shown in red | hand-written |
| Adjustment on `cycle_start` / on `cycle_end` | included / excluded | hand-written |
| Fractional adjustment (2.5 days) | shown as `2.5`, whole numbers shown as `3` not `3.0` | hand-written |
| Feb 29 hire date | rolls to Mar 1 in non-leap years, same as JS | hand-written (no such employee today) |
| Dec 31 / Jan 1 boundary | year arithmetic correct | hand-written |
| Type casing ('vacation') | normalized to 'Vacation' at import and by the form's radio buttons | import test |
| Departed employee | historical cycles still compute in the Former employees archive; hidden from the viewer page, dashboard and pickers; every one of their calendar events is deleted; requests after `departed_on` refused by the form | hand-written |
| Hire date edited | every cycle recomputes; the edit form shows a before/after table and requires confirmation | manual + audit |
| Employee renamed (marriage, nickname) | balances unchanged; every past PTO and birthday event for that person is retitled on the next sync (a PATCH, which trips no guard); audit summaries keep the old name; the edit form says so | hand-written |
| Rehire | simply a new row with the new hire date; the old row stays in Former employees; the form warns about the duplicate name; balances start from the new hire date | hand-written |

**How to prove the port matches the sheet on all 177 rows:**

1. `tests/fixtures/sheet_snapshot_2026-09-14.json`: the 15 employees, all 177 rows with the sheet's stored E and F values and G notes, plus the 15 expected current-cycle balances (Walker R 2/3 and 14/15; Catuto 3/3, 12/15; Ketter 2/3, 10/15; Modzik 3/3, 13/15; Swafford 1/3, 15/15; Danalewich 3/3, 15/15; Moss 3/3, 0/5; Coleman 3/3, 10/10; Walker J 2/3, 0/5; six 2026 hires 0/0).
2. `tests/balance_test.php` (hand-rolled runner, no PHPUnit, because PHPUnit needs Composer): replays the fixture with `$today = 2026-09-14` and asserts 177/177 row matches, 15/15 balance matches, then runs the edge-case table above. Prints one green or red line. Runs from the XAMPP command line before every upload and from Admin > Self-test on the server after every PHP version bump.
3. The importer runs the same check **inside its transaction** and rolls back on any mismatch (section 9), so a wrong port can never land in the database.
4. `tools/verify.php --compare-url=<doGet URL>` fetches the live Apps Script JSON and diffs it against the current-cycle and next-cycle numbers from `lib/balance.php`, employee by employee, as a second acceptance check while the sheet is still live. It has no meaning once the sheet is frozen, so it belongs to the shadow-mode weeks, not to the time after cutover.
5. `tools/balance_oracle.py`, the Python re-implementation that produced today's 0-mismatch result, is kept as an independent third implementation for the next time a balance is questioned.
6. The nightly job re-runs the self-test and emails you only if it fails.

---

## 6. Screens

Kept to what a 15-person office needs. Roles in brackets: `admin` (you), `editor` (the office manager), and `everyone` for the one page that needs no login.

0. **Viewer page** `view.php` [everyone, no login]. The replacement for the bookmarked `timeoff518652351` page, deliberately laid out the same way so nobody has to relearn it: the heading, the Google Calendar embed `<iframe>` with the same `src` parameters (all five calendars plus Google's public US Holidays calendar, `ctz=America/New_York`, same colours), and the "Remaining Days After Scheduling Time Off" table with Employee, Hire Date, PTO, VAC for active employees, sorted the way the sheet lists them. The table is rendered server-side from the database by the same `lib/balance.php` the admin screens use, so it is always current and there is no JavaScript fetch to Google Apps Script any more. Two small additions that cost nothing: a "cycle renews MM/DD" hint under each hire date, and the "After MM/DD/YYYY: N PTO / N Vac" note for anyone who already has time booked in their next cycle. It is served at one unguessable address, `/view.php?k=<viewer_token>`, where the token comes from `config.php` and the page answers 404 to anything else; the old URL redirects to it (section 10 step 1), and it sends `X-Robots-Tag: noindex`. Beyond those two hints it shows nothing the current page does not already show every employee. The calendar grid inside it is Google's own embed, which shows events to anonymous visitors only if the calendars are public, and otherwise only to people signed in to a Google account the calendars are shared with **[verified]**; the new page uses the identical `src` and the plan changes no sharing settings, so it behaves exactly as today.

1. **Login** [both]. Email + password. A failed login pauses half a second and is audited with IP; there is no lockout counter (two accounts on an unlinked HTTPS URL with 12-character passwords do not need one). First login with a temporary password forces a change. No "forgot password" email; an admin issues a new temporary password.

2. **Dashboard** `index.php` [both]. One row per active employee: name, hire date, years of service, current cycle dates, PTO left / allotment, Vacation left / allotment, Total (red if negative, amber if 1 or fewer), and the next-cycle preview in the sheet's own wording: "After 03/08/2027: 3 PTO / 15 Vac". Below: **Upcoming** grouped Today / This week / Next week / Later this month (time off, birthdays, holidays, factory closings), and the **sync and backup status strip** ("All 5 calendars in sync, last run 12 min ago; last backup 03:20 today", "Calendar sync is in dry-run mode", or a red banner naming the failing calendar). Buttons: + Time off, + Employee, Sync now. Toggle: show former employees.

3. **Time off form** `request.php` [both]. The 10-second screen. Employee select autofocused, active employees only, last-used employee first. PTO / Vacation as two large segmented buttons. Start date; end date auto-fills to start. Optional note. As soon as dates are chosen a live preview line appears (via `preview.php`, session-checked like every other screen, wired from `assets/app.js`, never inline): "3 working days. Vacation 13 of 15 left in cycle 03/08/2026 - 03/07/2027." Non-blocking warnings, because the sheet never blocked either: no working days in range; balance goes negative; overlaps an existing request; spans an anniversary (offers "split into two rows"); includes a company holiday; before hire date; after departure date. Enter saves. After save the form resets but keeps the employee, so several single days can be entered in a row. Confirmation says whether the calendar updated inline, will within 15 minutes, or is in dry-run mode.

4. **Time off list** `requests.php` [both]. Newest first, filters for employee, type, cycle year. Columns mirror the sheet: employee, type, start, end, working days, PTO remaining after, Vacation remaining after, note, calendar state (synced / pending / error). Row actions: edit, delete (confirm dialog; full snapshot goes to `audit_log`; the Google event is deleted in the same request, and the nightly reconcile catches it if that call fails). Export CSV in the sheet's column layout.

5. **Employees** `employees.php` [both]. Active employees with hire date, years of service, birthday as MM/DD, current allotments and Total. **+ Add employee**: last name, first name (live preview of "Last, First" exactly as it will appear on calendars), hire date, birthday month + day dropdowns with optional year. If an employee already has that name, active or former, the form warns (a rehire is simply a new row with the new hire date; the old row stays in the archive). Saving marks the birthdays calendar dirty. **Former employees** tab: the archive. Each departed person with hire date, departure date, and a link to their full ledger (every cycle, every request, running balances) exactly as it looked on their last day, plus Export CSV for that person. Nothing here is on any calendar; it exists so a question like "how much vacation had she used when she left" can be answered years later. A departed person can be un-departed (typo, or someone who changed their mind within days), which puts their rows back into the desired set so the next 15-minute sync re-creates their events; every delete clears the stored event id first, so nothing is duplicated.

6. **Employee detail** `employee.php?id=N` [both]. Profile edit (changing hire date shows a before/after balance table for every cycle and requires a confirm checkbox; changing the name shows "N calendar events will be retitled" and requires the same). Cycle-by-cycle ledger from hire date through next cycle: allotment, adjustments with reasons, used, remaining, and every request with its running balance (the sheet's E/F/G grouped by cycle). **Adjustments panel**: + grant / deduct N days, kind, effective date (the resulting cycle is displayed), mandatory reason. **Mark as departed on <date>**: one button, one confirmation. It keeps every database row (the person moves to the Former employees archive), removes them from the viewer page, pickers and dashboard, and deletes every calendar event that belongs to them: all PTO/Vacation events, past and future, and every birthday occurrence. The deletes run right there, row by row against the stored `google_event_id`s, each success clearing the row's id, with a progress line; anything that fails is finished by the departed-cleanup step that opens every 15-minute sync (section 7), so nothing depends on you noticing. No more deleting sheet rows to make someone disappear.

7. **Events** `events.php?cal=holidays|factory|sales` [both]. One tab per event calendar: Company Events & Holidays, Factory Closings, End of Month Sales. Table with an inline add row (title, start, end), edit and delete per row, calendar state per row. "Copy last year's events to <next year>" for the fixed holidays. Posting an end-of-month sale is: open the Sales tab, type the title and date, Enter; it is on the calendar and the viewer page within seconds. The 2023 party, luncheon and sale rows that sit on the Factory Closings sheet today are imported where they are and flagged so they can be moved to the right tab with one click.

8. **History** `history.php` [admin; editors see their own actions]. The audit log: when, who, what, before → after, filterable by employee, table, user, date. "Restore" on deleted time-off and event rows re-inserts from the stored snapshot.

9. **Admin** `admin.php` [admin]. **Users** (add, role, temporary password, deactivate). **Calendars** (the five IDs, active and sync_enabled toggles, Test connection, Preview plan / dry run, Sync now per calendar or all, sync_mode switch, the last run's result per calendar with a link to `sync.log`, the **Unmanaged events** list built on the fly from `events.list` filtered to events without our `lsp` property, with "adopt as..." and explicit delete checkboxes; the **Orphaned events** list (events that carry our `lsp` key but whose row no longer exists) with re-adopt and delete per event; **Wipe and regenerate birthdays** (one confirmation: deletes everything on the birthday calendar and re-creates this year and next for active employees); **Reset sync state** (clears every stored event id; used between the rehearsal and the real cutover); and a Force button that overrides the mass-delete guard after you have read its message). **Send test alert** (proves the alert email actually arrives; do this during setup). **Self-test** (runs `balance_test.php` in the browser). **Export JSON now / download latest backup**. Footer shows app version, engine version, schema version, PHP version and handler.

10. **Balance preview** `preview.php` [both]. The only JSON the app serves: given employee, kind, start and end it returns the working-day count, the resulting balance and the warnings for the time-off form's live line. Session-checked like every other screen, read-only, 30 lines.

Deliberately not built now, listed in README under "later if needed": a JSON API for other tools (the old `doGet` endpoint had exactly one consumer, the bookmarked page, and that page is now the app's own; a keyed endpoint is 40 lines if a Slack bot or spreadsheet ever wants balances), a `viewer` login for staff (the viewer page and the five shared Google Calendars already give everyone the view they have today), ICS feeds (an escape hatch if the Google side is ever retired; Google refreshes URL feeds only every 12-24 hours **[likely]**, so they would be a fallback, not the primary channel), an in-app migrations runner, a login lockout counter, a stored sync-run history, per-employee logins, email invites, a month-grid calendar page, PWA.

---

## 7. Google Calendar sync design

**Decision.** Keep all five Google Calendars, same IDs, same titles, same all-day semantics, readable by staff exactly as today (whether that is public sharing or their own Google accounts; the viewer page's embed relies on it and nothing here changes a sharing setting). The database is the only source of truth; Google is a mirror. Nobody edits in Google.

**What the current script actually does (and why the new design is different).** Reading `updateCalendarAndPTO` and `syncSimpleEventSheet` closely: the delete pass compares the sheet's inclusive end date to `ev.getEndTime()`, which for an all-day event is the exclusive next-day midnight. They never match, so every trigger run deletes every event on the PTO and event calendars and recreates them. Consequences that shape the cutover: no Google event ID on those calendars survives a run, so adoption is only meaningful after the triggers are silenced; and everything currently on the calendars is an exact title-and-date product of the sheet, so exact-match adoption will find nearly all of it. The birthday routine (`addBirthdaysToCalendar`) is the opposite case: it adds every existing event's title-plus-date key to `eventTitles` before the delete loop, so the delete loop can never fire and **nothing has ever been removed from the birthday calendar**. It has accumulated every year it has run for (2023 to 2027) and keeps departed and old-name birthdays forever. You have said departed people should come off the calendars, so the birthday rule below does that deliberately, and the cutover cleans up what the old script left behind.

**Ownership.** The five calendars already belong to the company Google account, which is the right place. Create the Cloud project and the service account signed in as that same account, add yourself as a second project Owner, and give a second person "Make changes and manage sharing" on each calendar so nobody is ever locked out. If that company account is a Google Workspace account (an `@lightsaberpromotions.com` sign-in rather than `@gmail.com`), the two Workspace prerequisites below apply; if it is a consumer Gmail account, neither does.

**Auth: a Google Cloud service account, calendars shared with it.** Google's service-account documentation states domain-wide delegation is not needed to access resources shared directly with the service account **[verified]**. Steps: create a Cloud project, enable the Google Calendar API (no billing needed **[verified]**), create a service account with no project roles, create a JSON key, then in Google Calendar share each of the five calendars with the service account's `client_email` at a "Make changes..." level **[verified]**. Request only the scope `https://www.googleapis.com/auth/calendar.events`; do not use `calendar.events.owned`, which covers only calendars the principal owns **[verified]**. The token flow is a JWT (`{alg: RS256}`, claims `iss`, `scope`, `aud: https://oauth2.googleapis.com/token`, `iat`, `exp <= iat + 3600`) signed with `openssl_sign` using the key's `private_key`, POSTed as `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`; the access token lives about an hour **[verified]** and is cached in `settings` for 55 minutes. Nothing expires, no consent screen, no refresh token. Use curl, not `file_get_contents`, because `allow_url_fopen` is root-controlled on cPanel **[likely]**; outbound 443 is in the stock CSF allowlist on Liquid Web **[likely]**.

**Test connection is a write probe, not a metadata read.** The `calendar.events` scope does not authorize `calendarList.*`, and `accessRole` is only exposed on CalendarList entries, so "read the metadata and check the role" would 403. Instead, for each calendar: `events.insert` a throwaway all-day event on 2000-01-01 titled "LSP connection test" (stamped with our `lsp` property so a leftover is recognisable), then `events.delete` it, and report OK only when both succeed. That proves writer access, which no read can.

Two Workspace-specific prerequisites, if the calendars are owned by a Workspace account rather than a consumer Gmail:

- The service-account address counts as an outsider. The Admin console setting for **secondary** calendars (Admin console > Apps > Google Workspace > Calendar > General, external sharing options) must allow "Share all information, and outsiders can change calendars"; otherwise the share dialog silently caps the service account at free/busy or read-only and every write returns 403/404 **[verified]**. Do not tighten that setting while you are in there: if the five calendars are public today, the same setting governs that, and the viewer page's embed would go blank.
- Organizations created on or after 2024-05-03 have secure-by-default policies that block service-account JSON key creation until an Org Policy Administrator sets `iam.managed.disableServiceAccountKeyCreation` to Not enforced for the project **[verified]**. A consumer Gmail project under "No organization" is not affected **[verified]**.

**Fallback ladder** (only the token function in `lib/calendar.php` changes; nothing else notices):
1. Key creation blocked and the admin will not override it: the **Apps Script bridge**. Keep a tiny Apps Script owned by the company account, deployed as a web app (Execute as me, access Anyone), whose `doPost` receives the desired event list from PHP and writes with `CalendarApp`, reusing the code that already exists. PHP must set `CURLOPT_FOLLOWLOCATION` (responses come back via a 302 to script.googleusercontent.com) and put a shared secret in the JSON body; web apps cannot set HTTP status codes, so success is `{ok: true}` **[verified]**. No Cloud project at all.
2. OAuth refresh token: one-time consent by the calendar owner from an Admin page. The consent screen must be **Internal** (Workspace) or **published to production** (Gmail) before you capture the token, or it dies after 7 days; send `access_type=offline&prompt=consent` or no refresh token is returned on re-consent **[verified]**. Needs a "Reconnect Google" button for the day the token is revoked.
3. ICS feeds only, with the 12-24 hour lag **[likely]**. Last resort.

**Mapping table.**

| cal_key | Source rows | Title | start.date | end.date (exclusive) | Extra |
|---|---|---|---|---|---|
| pto | every `time_off` row whose employee is active | `Last, First - PTO` / `Last, First - Vacation` | start_date | end_date + 1 | `extendedProperties.private.lsp = time_off:<id>` |
| birthdays | every `birthday_events` row whose employee is active | `Last, First Birthday` | date(year, month, day) (Feb 29 rolls to Mar 1) | +1 | description "Birthday"; `lsp = birthday:<employee_id>:<year>` |
| holidays / factory / sales | `events` rows with that cal_key | title as typed | start_date | end_date + 1 | `lsp = event:<id>` |

All-day events use `start.date` / `end.date` with an exclusive end, the same +1 day the current script already does **[verified]**. Let Google generate event IDs; a custom ID that was ever deleted can never be reused (409), so deterministic IDs are a trap **[verified]**.

**Birthday rule.** The birthday calendar is the one calendar that is regenerated rather than adopted, because every event on it is derivable from two columns. For every active employee with a month and day, a `birthday_events` row exists for the current year Y and Y+1; the nightly job and every employee save top these up, and the sync creates the matching events. When an employee is marked departed, their rows and events are deleted. A rename retitles that person's occurrences (a PATCH). At cutover, Admin > Wipe and regenerate birthdays deletes everything the old script left on that calendar (2023 to 2027, departed and old-name people included) and creates Y and Y+1 for active staff: the clean-up you asked for, in one click. Prior-year birthdays disappear with it; if anyone wants them, say so (section 12) and the regeneration range becomes 2023 to Y+1 instead.

**Algorithm** (`lib/sync.php`, `sync_calendar($cal_key, $trigger, $dry_run)`), guarded by MySQL `GET_LOCK('pto_sync_<cal_key>', 0)` so cron and an inline run never overlap:

1. Build **desired** from the database: for each mirrored row, {key, title, start, end_exclusive, fingerprint = sha1(title|start|end_exclusive)}.
2. **Departed cleanup, then the incremental pass** (inline and every 15 minutes). First, every `time_off` and `birthday_events` row whose employee is departed and whose `google_event_id` is not NULL is DELETEd by id and the id cleared; these are exempt from the mass-delete guard because a person explicitly marked departed is not an accident. Then rows with `google_event_id IS NULL` → first query `events.list?privateExtendedProperty=lsp=<key>` in case an earlier insert's response was lost, otherwise INSERT; store the id and fingerprint. Rows whose stored fingerprint differs from the current one → PATCH; a 404 clears the id so the next pass re-inserts.
3. **Reconcile pass** (nightly, Sync now, Preview plan): one `events.list` with `singleEvents=true`, `timeMin=2021-01-01`, `timeMax=2040-01-01`, `maxResults=2500`, `fields` limited to id/summary/start/end/extendedProperties, following `nextPageToken` if it ever appears (well within quota: 10,000 requests/min/project, 1,000,000/day **[verified]**). Then: a remote event carrying an `lsp` key that no live row owns → DELETE only when `audit_log` records a delete for that row or the row belongs to a departed employee; otherwise (which after a database restore means a request entered after the backup was taken) it is listed as **orphaned** on the Admin page with re-adopt and delete choices, and never removed automatically; a row whose `google_event_id` is not in the listing → clear it, re-insert; a remote event whose title or dates differ from desired (a hand edit in Google) → PATCH back; a remote event with no `lsp` property → **unmanaged**, reported, never deleted automatically. That last rule is a deliberate change from today's wipe-everything semantics, and it is what makes a mistyped calendar ID or a half-imported database harmless.
4. **Guards**, evaluated on the plan before any write: abort if the desired set for `pto` or `birthdays` is empty (a fresh or wrong database pointed at the real calendars); abort if planned deletes exceed 25 events or 20% of that calendar's mirrored rows (departed-cleanup deletes are not counted, so a long-tenured leaver with 60 events never trips it); refuse all writes while `settings.sync_mode = dry_run` and write the plan to `sync.log` for review. Admin > Force runs the plan anyway after a human has read the refusal message.
5. Execute creates, then patches, then deletes, updating the row's `google_event_id` / `synced_fingerprint` after each API call (a successful DELETE sets both back to NULL; the sync never removes a database row) so a crash mid-run resumes cleanly. Append one summary line to `pto_data/logs/sync.log`; update `calendars.last_sync_*`; on success clear `calendars.dirty`.

**Adoption is a one-time tool, not a branch of the sync.** `tools/adopt_existing_events.php` (also reachable as Admin > Calendars > Adopt existing events) lists each calendar's events without an `lsp` property and matches them to rows: first by exact fingerprint, then by the same dates and a case-insensitive, whitespace-trimmed title (this catches the four lowercase "vacation" titles and the one trailing-space factory title). It covers the PTO calendar and the three event calendars; birthdays are regenerated instead (above). A match writes `google_event_id`, stamps the `lsp` property and PATCHes the title to canonical form; no INSERT. It writes regardless of `sync_mode`, because writing is its job, and it refuses to run while any row already carries a `google_event_id` (run Reset sync state first). It is idempotent and prints counts. After a database restore, the Orphaned events list is the tool for anything entered after the backup; adoption only looks at events without an `lsp` key.

**Triggers.** Every write sets `dirty = 1` on the affected calendar and attempts the incremental pass inline with a 10-second curl budget. `cron/sync.php` every 15 minutes syncs whatever is still dirty. `cron/nightly.php` at 03:05 reconciles all five regardless. Admin > Sync now runs on demand.

**Failure handling.** 401 → refresh the token once and retry. 403 `rateLimitExceeded` / 5xx → back off 1, 2, 4, 8 s, then record the error on the row's `sync_error`, continue with the rest (run recorded as partial). 404 on PATCH → recreate; 404 on DELETE → treat as done. Network down → run fails, calendar stays dirty, cron retries. Data entry is never blocked: the row is saved and the balance is right immediately; only the mirror lags. The dashboard strip turns red when any active calendar's latest run failed or it has been dirty for 30+ minutes. **One alert channel.** After 3 consecutive failures, and when the nightly self-test or backup check fails, PHP `mail()` sends one email per day to `alert_email`, from the `alert_from` address in `config.php`; the three cron lines redirect their output to `cron.log`, so cPanel's "email output to" is not used. Because the domain's mail is probably on Google Workspace, the server may not be in its SPF record; Admin > Send test alert during setup, and if the message is missing or in spam, cPanel > Email Deliverability shows the exact SPF (`ip4:` for the server) and DKIM records to add. Note that service accounts cannot add attendees without domain-wide delegation **[likely]**, so never design an "invite the employee" feature on this path; nothing here needs it.

**Calendar sync stopped: how to reconnect** (goes in README verbatim). Symptoms map to causes: `401 invalid_grant` = the service-account key was deleted or rotated; `403` = the calendar share was removed, the Calendar API was disabled, or the scope is wrong; `404` = wrong calendar ID or the share was removed. Fix in three steps: (1) console.cloud.google.com, signed in as the company account > IAM & Admin > Service Accounts > `lsp-pto-sync` > Keys > Add key > JSON (if the service account or the project is gone, recreate them by section 10 step 8; a deleted project can be restored for 30 days), (2) upload as `pto_data/google-service-account.json`, mode 600, (3) re-share any calendar that no longer lists the service account, then Admin > Calendars > Test connection. While it is broken nothing is lost: rows keep saving, calendars stay dirty, and the next successful run catches up.

**One-time cutover so nothing is duplicated or wiped, in this order:**

1. Rehearse for a week against five throwaway calendars you create for the purpose: paste their IDs in Admin > Calendars, set `sync_mode = live`, Sync now, look at the result on a phone. Then delete the throwaway calendars, run Admin > Reset sync state (clears every stored event id, so the rehearsal's ids can never be mistaken for real ones), switch back to `dry_run` and paste the real IDs. Adoption refuses to run while any stored id remains, so this cannot be skipped by accident.
2. **Silence the Apps Script first.** In the Apps Script editor delete every time-driven trigger, then replace the body of each of `updateCalendarAndPTO`, `addBirthdaysToCalendar`, `syncSimpleEventSheet` and `calculateAndBirthdays` with `throw new Error('Calendar writes moved to pto.lightsaberpromotions.com');` and save, so a menu item clicked out of habit fails loudly instead of wiping the new app's events within one trigger interval. Leave `calculateAll` and `calculateRemainingDays` alone; the next step needs them. Do this before adoption, because adoption records event IDs that the old script would otherwise destroy on its next run.
3. Run `calculateAll` once from the Apps Script editor so the E/F/G columns are current (nothing has recomputed them since the triggers went), confirm the last row entered has values in E and F, then re-export, re-import with "replace all" (section 9), self-test green. Export and import on the same calendar day, because the gate's `$today` is the export date.
4. Admin > Calendars > Test connection on all five (the insert-then-delete probe must pass on each).
5. Adopt existing events (it writes regardless of `sync_mode`), then Preview plan on each calendar. Expected: PTO roughly 170-177 adopted, events about 43 adopted, zero PATCHes (adoption already retitled the odd ones), zero DELETEs, and at most a handful of INSERTs (the old script's search-based matching collapsed same-title overlapping rows into one event). More than ten inserts on any calendar, or any delete: stop, nothing is live yet. Then Wipe and regenerate birthdays (in dry-run it only prints what it would do; check the count, then switch to live for this one action) so departed people's birthdays come off. If a departed person's old PTO events are still on the PTO calendar they appear as unmanaged; tick and delete them there.
6. Set `sync_mode = live`, Sync now (all). Confirm two staff phones still show their calendars unchanged.
7. Retire the old page three ways, because phones cache pages: (a) the redirect rule from section 10 step 1, then open the old bookmark on one phone and confirm it lands on the new page with the calendar grid actually rendered, not just the table; (b) overwrite the old HTML file itself with a one-line `<meta http-equiv="refresh" content="0;url=<viewer URL>">` page, so a missed rule still lands on the new page; (c) redeploy `doGet` as a new version of the existing deployment (Deploy > Manage deployments > pencil > New version) that returns a single row whose `employee` field reads "This page has moved to pto.lightsaberpromotions.com", so even a cached copy of the old page tells staff where to go instead of showing frozen balances. After a month, archive the deployment. From here on nothing should call `doGet`; `verify.php` has no meaning once the sheet is frozen.
8. Rename the sheet "ARCHIVE - LSP Calendar Update (replaced <date>)" and protect all ranges.

---

## 8. Auth and roles

**Roles.** `admin` (you): everything, including users, calendars, sync mode, imports, exports. `editor` (the office manager): employees, time off, adjustments, events, own history. Start with those two accounts and make a second admin eventually so nobody is locked out. The viewer page and the five shared calendars remain the read channel for everyone else; a `viewer` login is on the later-if-needed list.

**The viewer page needs no account**, as today. Its protection is the unguessable address, the same model the current `timeoff518652351` page relies on, and it shows only what that page already shows every employee (names, hire dates, remaining days). If you ever want more than that, a single shared office password on that one page is a 20-line change.

**Passwords.** `password_hash()` / `password_verify()` with `PASSWORD_DEFAULT`, upgraded on login via `password_needs_rehash`. Minimum 12 characters. No self-service reset and therefore no outbound-email dependency for login: an admin sets a temporary password from Admin > Users and the user must change it at next login. The page says only "invalid email or password". All logins and failures are audited with IP.

**Sessions.** Native PHP sessions with `session.save_path` pointed at `pto_data/sessions`, `session_name('lsp_pto')`, cookie path `/`, cookies `Secure`, `HttpOnly`, `SameSite=Lax`, `use_strict_mode`, 12-hour idle timeout, `session_regenerate_id(true)` on login and role change, logout destroys the session. A per-session CSRF token is a hidden field on every POST and is checked in `bootstrap.php` before any handler runs. Every screen begins with `require_login()` and, where relevant, `require_role('admin')`.

**Environment guard.** `config.php` carries `environment`, either `production` or `local`. Anything but `production` forces dry-run and refuses every Google write, whatever the database says, so a copy of the production database on your laptop can never touch the real calendars.

**Free hardening.** HTTPS comes from AutoSSL, which adds the new subdomain to the account's certificate on its next run after DNS resolves (Let's Encrypt is the default provider on current cPanel **[verified]**); turn on Force HTTPS Redirect for the subdomain once the certificate is there. Headers from bootstrap: `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; frame-src https://calendar.google.com`, which blocks inline `<script>`, inline `onclick=` handlers and inline `<style>`, so all JS and CSS live in the two asset files; the `frame-src` entry exists for the Google Calendar embed on the viewer page (without it `default-src 'self'` blocks the iframe and the page shows an empty box), and both the form's live preview and the embed are tested with the header on; `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Strict-Transport-Security`. `display_errors` off, errors logged to `pto_data/logs/error.log`, friendly error page with a reference ID. PDO prepared statements everywhere with `ERRMODE_EXCEPTION`; all output through one `h()` escaping helper. Secrets (`config.php`, the service-account JSON, `my.cnf`) live outside the document root at mode 600. `setup.php` is protected by a one-time install token from `config.php`, refuses to run once a user exists, and is deleted after install.

---

## 9. Migration plan from the xlsx

The import is `tools/import_sheet.php`, runnable from the XAMPP command line and from Admin > Import, with a dry-run report and a commit button. It accepts the five sheets as CSV (File > Download > CSV, one per sheet) or the existing `xlsx_dump.json`. Rehearse locally as many times as you like; on the server it runs twice, once before the calendar rehearsal and once more with "replace all" on cutover night.

**Step 1: dry run (no writes).** Parse and normalize:
- Employees (15): split "Last, First" on the first comma into `last_name` / `first_name`, trimmed; assert that `CONCAT(last_name, ', ', first_name)` reproduces the original string byte-for-byte (this is what keeps calendar titles identical). `hire_date` from column B. Birthday month and day from column C; `birth_year` kept only when the sheet's year is below 2023 (keeps 1972, 2003, 2006, 2003; nulls the eight 2023 and three 2026 placeholders). `legacy_row` recorded.
- Time off (177): names resolved to `employee_id` (an unknown name is a hard failure, none expected); type lower-cased then mapped to `PTO` / `Vacation` (fixes the four lowercase rows); start > end is a hard failure; rows inserted in sheet order so `id` order reproduces the script's tie-break. The Danalewich 2026-05-16 Saturday-only row and the 6-working-day row are imported unchanged and listed as warnings, because the sheet counts them that way and the calendar shows them today.
- Events: all rows verbatim into `events` with `cal_key` holidays / factory / sales, titles trimmed, including the misfiled 2023 party, luncheon and sale notes that sit on the Factory Closings sheet; they stay where they are and are flagged "possibly misfiled" for you to move from the Events screen later.
- Adjustments: none exist; the table starts empty.
- The report prints counts (15 employees, 177 requests, about 12 holiday, 26 factory and 5 sales rows) and every warning.

**Step 2: commit, behind a gate.** One transaction inserts everything with `created_by NULL` and one `audit_log` row per table ("imported from LSP Calendar Update <date>"). Still inside the transaction, the importer runs `lib/balance.php` over the imported rows with `$today` = the export date and checks: (a) all 177 running balances equal the sheet's stored E and F; (b) every G note matches; (c) the 15 current-cycle balances equal the known values; (d) per-employee row counts and the employee sheet's D/E allotment columns (and their Combined Total) match. **Any mismatch prints a row-level diff and rolls back; nothing partial can land.** On success it writes `pto_data/migration-report-<date>.txt`. The importer refuses to run against a non-empty `time_off` table unless "replace all" is ticked; "replace all" means: inside the same transaction, delete `birthday_events`, `adjustments`, `time_off`, `events`, then `employees` (in that foreign-key order), reset the auto-increment counters, then import; `users`, `calendars`, `settings` and `audit_log` are untouched, and one `audit_log` row records the replacement.

**Step 3: verify by eye.** Admin > Self-test must show 177/177 and 15/15. `tools/verify.php --compare-url=<doGet URL>` diffs the live Apps Script JSON against the app's balance engine. Compare the dashboard against the sheet for two employees by hand.

**Step 4: calendar rehearsal** against throwaway calendars (section 7, cutover step 1), one week, while the sheet stays live and the office manager keeps entering requests in the sheet only (about four rows a week, so re-importing later is cheap).

**Step 5: cutover on a Friday evening**, in the order given in section 7: triggers off, final export and re-import with "replace all", self-test green, Test connection, adopt existing events, Preview plan, go live, old page redirected to the new viewer page, sheet archived.

**Step 6: first Monday.** The office manager enters the week's requests in the app only. You check the dashboard against what she would have expected from the sheet. After a month of clean history the archived sheet is left alone.

**Rollback** until step 5 is complete: nothing has changed for staff. After it: put the redirect rule in `public_html/.htaccess` back the way it was (section 10 step 1 has you keep a copy) so the bookmark serves the original page again, restore the old HTML file from the copy you kept, restore the original function bodies in the Apps Script and re-enable the triggers, un-protect the sheet, and re-enter the handful of rows added since. The nightly `.sql.gz` covers the app side.

---

## 10. Deployment on cPanel, step by step

Everything is done through the cPanel web UI, File Manager and a browser. No SSH, no Composer, no WHM changes. The one DNS step (the subdomain's A record) happens automatically if the domain's DNS is hosted on this cPanel server, which is the usual case; otherwise it is one record wherever the zone lives. (Your Liquid Web account is very likely a managed VPS with WHM root **[verified]**, which means adding a newer PHP version is a click in WHM > EasyApache 4 if you ever want it; the only step below that might need it is choosing PHP 8.3+.)

1. **Subdomain.** cPanel > Domains > Create a New Domain: `pto.lightsaberpromotions.com`, Document Root `public_html/pto` (overwrite the prefilled value if cPanel proposes `public_html/pto.lightsaberpromotions.com`; either is inside `public_html`, so the WHM "Restrict document roots" setting is satisfied and nothing needs changing **[verified]**, but the rest of these steps assume the short name). Untick "Share document root" if that option appears. If the domain's DNS is on this server the record is created for you; otherwise add an A record at your DNS host pointing at the server IP. Then cPanel > SSL/TLS Status: make sure the new subdomain is included (recent cPanel versions exclude newly created domains from AutoSSL by default **[likely]**), click Run AutoSSL, and wait for the padlock rather than the daily run; only then turn on Force HTTPS Redirect for it in cPanel > Domains. Before the first visit, confirm `public_html/pto/.htaccess` exists with `RewriteEngine On` (it is in the zip; section 3 explains why this account needs it).
   **At cutover, not before:** open `public_html/.htaccess` in File Manager (Settings > Show hidden files) and copy it somewhere safe first. It holds the rule that serves anything starting with `timeoff518652351` from the static page, above a catch-all redirect to `lsp.lightsaberpromotions.com`. Change that one rule, keeping it above the catch-all, into a redirect: `RewriteRule ^timeoff518652351 https://pto.lightsaberpromotions.com/view.php?k=<viewer_token> [R=302,L,NC]`. A 302 rather than a 301 because browsers cache 301s for good, which would make a rollback or a token change impossible to undo on staff phones. Do not add this through cPanel > Domains > Redirects: cPanel appends its rule at the bottom of the file, below the existing `[L]` rules, where it would never fire **[verified]**.
2. **PHP version and handler.** cPanel > MultiPHP Manager: select the domain, choose the newest 8.x listed, at least ea-php83; if only 8.2 is offered, ask Liquid Web support to add ea-php84 before go-live (PHP 8.2 security support ends 2026-12-31). MultiPHP INI Editor for that domain: `memory_limit 128M`, `max_execution_time 90`, `display_errors Off`. Do not rely on `date.timezone` there; the code sets it. Note the PHP handler shown in MultiPHP Manager (or by `setup.php` in step 6, which prints `php_sapi_name()` and the user PHP runs as): with PHP-FPM, lsapi or suPHP the app runs as `CPUSER` and a 700 `pto_data` is fine; if the handler is DSO (PHP runs as `nobody`), ask support to switch the domain to PHP-FPM before continuing. The setup page also shows a red/green checklist of the required extensions; on a CloudLinux server with "Select PHP Version" you can tick any missing extension yourself **[likely]**.
3. **Database.** cPanel > MySQL Database Wizard: database `CPUSER_pto`, user `CPUSER_pto`, generate a long password, ALL PRIVILEGES **[verified]**.
4. **Files.** cPanel > File Manager: create `/home/CPUSER/pto_data` (permissions 700) with `backups`, `logs`, `cache`, `sessions` inside. Upload `pto_app.zip` to `/home/CPUSER/` and Extract (creates `/home/CPUSER/pto_app/`). Upload `pto_public.zip` to `/home/CPUSER/public_html/` and Extract into the document-root folder from step 1. Delete both zips.
5. **Config.** Copy `pto_app/config.example.php` to `pto_data/config.php` and fill in DB name/user/password, `install_token` (any long random string), `base_url` (`https://pto.lightsaberpromotions.com/`), `viewer_token` (the unguessable part of the viewer page's address, generated like a password and then left alone, since it is baked into the bookmark redirect), `environment` (`production` here, `local` on XAMPP), `alert_email`, `alert_from`. Create `pto_data/my.cnf` (permissions 600) with `[client]`, `user=`, `password=` for the mysqldump line. Upload the Google service-account JSON as `pto_data/google-service-account.json` (permissions 600).
6. **Install.** Browse to `https://pto.lightsaberpromotions.com/setup.php?token=<install_token>`. It checks PHP version, handler, extensions and the folder's `.htaccess`, connects to the database, runs `migrations/001_init.sql`, seeds the five calendars, creates your admin account, runs the self-test on the bundled fixture (must show 177/177), sets `sync_mode = dry_run`, then tells you to delete `setup.php`. It refuses to run again once a user exists.
7. **Cron.** cPanel > Advanced > Cron Jobs, three entries; the two PHP lines use the EA PHP binary (not `/usr/local/bin/php`, which may be a different version) **[verified]**, and the backup line uses `mysqldump`, which is on every cPanel box **[likely]** and needs no PHP:
   ```
   */15 * * * *  /opt/cpanel/ea-phpNN/root/usr/bin/php -q /home/CPUSER/pto_app/cron/sync.php    >> /home/CPUSER/pto_data/logs/cron.log 2>&1
   5 3 * * *     /opt/cpanel/ea-phpNN/root/usr/bin/php -q /home/CPUSER/pto_app/cron/nightly.php >> /home/CPUSER/pto_data/logs/cron.log 2>&1
   20 3 * * *    /usr/bin/mysqldump --defaults-extra-file=/home/CPUSER/pto_data/my.cnf CPUSER_pto | gzip > /home/CPUSER/pto_data/backups/pto-$(date +\%F).sql.gz
   ```
   `NN` is the version you chose in step 2 (83 or 84); confirm that binary exists in File Manager before saving the cron. Each PHP script prints `PHP_VERSION` and a one-line summary into the log so a wrong-binary mistake is visible. Leave cPanel's "email output to" blank: with the redirects it would never fire, and alerts come from the app instead.
8. **Google side** (one time, about 20 minutes, signed in as the company account that owns the calendars; see section 7 "Ownership"). console.cloud.google.com > New project "LSP Time Off" > IAM & Admin > add yourself as a second Owner > APIs & Services > Library > Google Calendar API > Enable. IAM & Admin > Service Accounts > Create `lsp-pto-sync`, grant no roles. Open it > Keys > Add key > JSON > download (if this is refused, see section 7 for the org-policy override or the Apps Script bridge). In Google Calendar, for each of the five calendars: Settings and sharing > confirm the owner and add a second "Make changes and manage sharing" person > Share with specific people or groups > add the service account's email > "Make changes and see event details" (the level formerly called "Make changes to events") **[verified]**. Workspace only: first confirm Admin console > Apps > Google Workspace > Calendar > General allows outsiders to change secondary calendars **[verified]**.
9. **Smoke test.** Admin > Calendars > Test connection: each calendar passes the insert-then-delete probe. If it times out, the first thing to check is WHM > ConfigServer Security & Firewall > TCP_OUT for port 443 (normally already allowed **[likely]**). Then Admin > Send test alert and confirm the email arrives in your inbox, not spam; if not, add the SPF/DKIM records cPanel > Email Deliverability suggests.
10. **Import and cutover** per section 9.

**Update procedure** (ten minutes, no SSH): edit locally, browse on XAMPP, run `php tests\balance_test.php`; if the schema changes, add `migrations/00N_*.sql` and bump `APP_VERSION`; zip `pto_app` and `pto_public` and keep the previous zips; File Manager > upload > Extract (files overwrite in place; `pto_data` is untouched); if the footer shows a yellow "schema behind" bar, phpMyAdmin > SQL > paste the new migration file; log in, Self-test. Rollback = extract the previous zips; only if a migration must be undone, restore last night's dump through phpMyAdmin > Import.

**Backups, three independent layers.** (1) Nightly: the `mysqldump` cron line writes `pto_data/backups/pto-YYYY-MM-DD.sql.gz`, and `nightly.php` writes `pto-YYYY-MM-DD.json` (employees, time off, adjustments, events as plain JSON with `engine_version`, the ten-year exit format), checks that the newest `.sql.gz` is under 36 hours old and not empty (else alert), keeps 60 daily plus the first of every month forever, and trims `cron.log` and `sync.log` entries older than 90 days; the dashboard strip goes red if the last backup is stale. (2) Admin > Export JSON now / Download latest backup, for the moment before a risky edit and for a monthly off-box copy to your PC. (3) The Liquid Web / cPanel account backup already covers the database and `/home/CPUSER/pto_data`; verify once that the path is included. **Restore drill**, once, on XAMPP: empty database, phpMyAdmin > Import the `.sql.gz`, point a `config.php` with `environment = 'local'` at it, log in, confirm the footer says calendar sync is in dry-run mode (the local guard, so the laptop can never write to the real calendars), Self-test green, and note that Admin > Calendars > Orphaned events is where anything entered after the backup would appear after a real restore. Write the result into README.

**Ten-year maintenance calendar.** Yearly: move MultiPHP to the newest 8.x, edit the two PHP cron paths, click Self-test. Yearly: confirm AutoSSL is still renewing. Every two years: rotate the service-account key (create new, upload, delete old; section 7 "how to reconnect" is the same procedure). Whenever someone leaves: Mark as departed; never delete their database rows (the app removes their calendar events for you and keeps their history under Former employees). Whenever an owner of the calendars or the Cloud project leaves: add a replacement second owner the same week.

---

## 11. Build plan and milestones

For one person who already knows PHP, working evenings and a couple of weekend mornings, with AI assistance: roughly 35-40 hours over 4-6 calendar weeks including the soak. (The critique's 30 is close; the extra few hours are the transactional import gate, the fixture test and the hand-rolled Google client, which are kept in full because they are what make the cutover safe.)

**Milestone 0, prerequisites (half a day).** Answer the open questions in section 12. Find out whether the company Google account that owns the five calendars is Workspace or consumer Gmail (section 12 question 1) and add a second owner to each calendar; create the Cloud project from that account with yourself as second Owner; do the Google Cloud steps and share the five calendars; if key creation is refused, decide between the org-policy override and the Apps Script bridge now, before any sync code is written. Choose the PHP version, create the subdomain and the database, and decide the viewer page's address.

**Milestone 1, the first usable thing (about 18 hours, weeks 1-2).** `migrations/001_init.sql`; bootstrap with timezone, auth, CSRF, layout; `lib/balance.php` with `tests/balance_test.php` green against the fixture (write this first and do nothing else until it is green); importer with dry run and the transactional gate; employees list, form and detail with rename/rehire handling; time-off list and the 10-second form with its `preview.php` live line; dashboard with balances, totals, next-cycle notes and the Upcoming strip; the viewer page with the calendar embed and the remaining-days table; audit log writes; `verify.php --compare-url`, so the shadow-mode weeks compare the app against the live `doGet` every day. At the end of this milestone the app runs on XAMPP and on the server in shadow mode: the sheet is still live and driving the calendars, the app shows the same numbers, and the office manager can already use the dashboard to answer "how many days do I have left". This is where you know the design is right, because the fixture test either says 177/177 or it does not.

**Milestone 2, cutover-ready (about 12 hours, weeks 2-4).** `lib/calendar.php` (JWT, token cache, thin REST wrapper with backoff, write-probe test); `lib/sync.php` (incremental and reconcile passes, guards, dry run, unmanaged list); `tools/adopt_existing_events.php` and `reset_sync_state.php`; the environment guard; Wipe and regenerate birthdays; events screens with "copy last year"; birthday rows and rule; dirty flags, inline and cron triggers; Admin > Calendars with Test connection, Preview plan, Sync now, Force; status strip, alert email and Send test alert; one-week rehearsal on throwaway calendars; cutover checklist executed. After this the sheet is archived.

**Milestone 3, finish and forget (about 8 hours, weeks 4-6).** Adjustments panel; mark-as-departed; hire-date edit diff; History with Restore; nightly self-test, JSON export, backup check and log trimming; the Orphaned events list; README runbook including "Calendar sync stopped"; restore drill.

Ongoing cost: minutes per week for the office manager; for you, one yearly PHP bump plus whatever a rule change needs. No hosting cost beyond the existing account; the Google Cloud project is free at this usage **[verified]**.

---

## 12. Open questions for Chris

Only the ones that still change the design. Already answered and built in: the calendars are company-owned; the bookmarked viewer page is the only `doGet` consumer and is replaced by the app; departed employees come off the calendars and are archived in the app; sales and factory closings stay postable; the app lives on a subdomain of lightsaberpromotions.com.

1. **Is the company Google account a Workspace account or a consumer Gmail?** A Workspace sign-in (`something@lightsaberpromotions.com`) means two admin-console prerequisites (external sharing of secondary calendars, and possibly the service-account key org policy); a Gmail sign-in means neither. If it is Workspace and the key policy cannot be lifted, the Apps Script bridge becomes the primary auth path instead of the fallback. Worth checking in the first hour of Milestone 0, because it decides which of two token functions gets written.
2. **Keep counting company holidays as used days, and keep the Saturday-only row?** Both are preserved for fidelity with the sheet. Changing either is a deliberate rule change (new `ENGINE_VERSION`) that would alter historical balances; it should not happen silently during the port.
3. **Viewer page protection: unguessable address only, as today, or a shared office password as well?** The design assumes address-only, matching the current page.
4. **Subdomain name.** `pto.lightsaberpromotions.com` is assumed throughout; anything works, it only has to be chosen before setup because the address is baked into `base_url` and the bookmark redirect.
5. **Is it all right for past birthdays to disappear from the birthday calendar?** The cutover wipes that calendar and regenerates this year and next for active staff, which is the simplest way to get departed people off it. Nobody scrolls back to see a 2024 birthday, so the design assumes yes; if you want the history kept, the regeneration range becomes 2023 to next year instead, at no real cost.