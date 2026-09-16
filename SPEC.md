# PTO Tracker: build specification

Authoritative spec for the application. Where `RECOMMENDATION.md` differs, this file wins.
Status: Milestone 2 in progress (section 14 adds Google Calendar sync); Milestone 1 complete.
Date: 2026-09-14.

## 1. What it is

One plain-PHP 8 + MySQL application on `https://pto.lightsaberpromotions.com/` (a cPanel subdomain of the
existing Liquid Web account) that replaces two Google Sheets + Apps Script PTO trackers:

| Group | key | Company name shown | Staff | Kinds of time off | Current viewer page |
|---|---|---|---|---|---|
| US office | `us` | Lightsaber Promotions | 15 | PTO + Vacation | https://lightsaberpromotions.com/timeoff518652351 |
| Manila artists | `manila` | Bright Bird Design | 18 | PTO only | https://lightsaberpromotions.com/manilapto |

Each group has its own employees, policy, event calendars, holidays, Google Calendars and viewer page.
Admins see both. The database is the only source of truth; Google Calendars become a read-only mirror
(Milestone 2). Balances are never stored; they are recomputed from `employees + time_off + adjustments + events`
on every read by one pure-function engine.

Confirmed facts that shape the build:
- The company Google account that owns all ten calendars is a **consumer Gmail** account (no Workspace).
- **Company holidays do not count against PTO** for anyone, in either group (new rule; the sheets counted them).
- The **viewer pages need a password** (one shared password per group) with a **long trust cookie** so staff
  are not asked again for months. Admin/editor screens use real accounts.
- **Departed employees** are removed from every calendar and from the viewer page but their history is kept.
- **Past birthdays** may be wiped from the birthday calendars at cutover.
- End-of-month sales, factory closings and company events must stay postable (both groups).

## 2. Folder layout (repo mirrors the server's home directory)

```
PTO-Tracker/                       (= /home/CPUSER on the server)
  public_html/pto/                 the ONLY web-served folder = document root of pto.lightsaberpromotions.com
      .htaccess                    exactly: RewriteEngine On   (blocks inheritance of the parent catch-all redirect)
      index.php login.php logout.php dashboard.php
      employees.php employee.php request.php requests.php preview.php
      events.php history.php admin.php view.php setup.php
      assets/app.css assets/app.js  (+ per-page assets/<page>.js if needed; no inline script/style anywhere)
  pto_app/                         code, not web-served
      lib/   bootstrap.php db.php auth.php csrf.php policy.php balance.php balance_db.php groups.php audit.php layout.php helpers.php viewer_auth.php
             google.php sync.php alerts.php (M2, section 14)
      cron/  sync.php nightly.php (M2, section 14.4)
      migrations/001_init.sql      (+ 00N_*.sql later; additive only)
      tools/ import_sheet.php smoke.php dev_reset.php sync_cli.php balance_oracle.py manila_oracle.py
      tests/ balance_test.php sync_test.php fixtures/us_snapshot_2026-09-14.json fixtures/manila_snapshot_2026-09-14.json
      config.example.php  README.md
  pto_data/                        private; on the server mode 700; locally git-ignored
      config.php  logs/  backups/  sessions/  google-service-account.json (M2)
  reference/                       source sheets, scripts, dumps, oracles (not deployed)
  run-local.bat                    starts the local server (see section 12)
```

Every file in `public_html/pto/` begins with
`require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';` which resolves to `PTO-Tracker/pto_app` locally
and `/home/CPUSER/pto_app` on the server. `bootstrap.php` defines `PTO_DATA = dirname(__DIR__, 2) . '/pto_data'`.

## 3. Runtime and conventions

- PHP 8.2 through 8.5 subset: `declare(strict_types=1)`, `DateTimeImmutable`, PDO with `ERRMODE_EXCEPTION`,
  no dynamic properties, no Composer, no framework, no CDN. Extensions used: pdo_mysql, curl (M2), openssl, mbstring, json, session.
- `date_default_timezone_set('America/New_York')` in bootstrap; **each group has its own timezone** and "today"
  for that group is computed in it (`group_today($group)`), used for cycle math and the viewer page. All business
  dates are `DATE`; timestamps are written by PHP, never MySQL `NOW()`.
- MySQL 8 / MariaDB 10.x common subset: InnoDB, utf8mb4, `DATE`, `MEDIUMTEXT` for JSON, `ENUM`s, no generated columns,
  `CHECK` constraints written but the app validates the same rules.
- One PHP file per screen, procedural style, small functions in `lib/`. All output through `h()` (htmlspecialchars).
  Every POST carries a CSRF hidden field verified in bootstrap. Flash messages via session.
- Security headers from bootstrap: `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; frame-src https://calendar.google.com`,
  `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`. `display_errors` off;
  errors to `pto_data/logs/error.log`; friendly error page with a reference id.
- `config.php` keys: `db` (host, name, user, pass), `base_url`, `environment` (`production` | `local`), `secret`
  (32+ random bytes, hex; signs trust cookies), `install_token`, `alert_email`, `alert_from` (M2).
  `environment !== 'production'` forces calendar sync to dry-run and refuses every Google write (M2 guard).
- Sessions: native, `session.save_path = PTO_DATA/sessions`, name `lsp_pto`, cookie path `/`, Secure (when https),
  HttpOnly, SameSite=Lax, `use_strict_mode`, 12-hour idle timeout, `session_regenerate_id(true)` on login.

## 4. Groups and policies

`groups` rows are seeded by the migration. Policy logic lives in code (`lib/policy.php`) keyed by `groups.policy_key`,
because tiers change once a decade and a table invites accidental edits.

| policy_key | kinds | Allotment per cycle by completed years of service at cycle start | Straddle rule | Adjustments |
|---|---|---|---|---|
| `us` | PTO, Vacation | Vacation: 0 (<1), 5 (1-4), 10 (5-9), 15 (10+). PTO: 0 (<1), 3 (1+) | **start_cycle**: a request is charged entirely to the cycle containing its start date | anchored to the cycle containing `effective_date` |
| `manila` | PTO | PTO: 0 (<1), 3 (1-4), 5 (5-9), 10 (10+) | **split**: working days are charged to whichever cycle each day falls in | same |

Shared rules (both groups), verified against the sheets today:
1. Cycle for a reference date D: `A = date(D.year, H.month, H.day)`; if `A <= D` then `cycle_start = A` else
   `date(D.year-1, H.month, H.day)`; `cycle_end` is exclusive, one year later. Feb 29 hires roll to Mar 1 in non-leap
   years (PHP `setDate` behaves like JS `new Date(y,m,d)`). A request starting on the anniversary is in the new cycle.
2. Years of service at X = `X.year - H.year`, minus 1 if `(X.month, X.day) < (H.month, H.day)`. Not clamped.
3. Days consumed = calendar days in `[start, end]` with ISO weekday 1-5, **minus company holidays** (section 5).
   No half days, no carry-over, no caps; balances may go negative and are shown in red.
4. Adjustments: `days` (fractional allowed, sign = grant/deduct) added to the allotment of the kind for the cycle
   whose `[cycle_start, cycle_end)` contains `effective_date`.
5. Running balance per request (the sheets' E/F columns): sort the employee's requests by `(start_date, id)`;
   per cycle, `remaining = allotment + adjustments - used so far`. For `split`, a straddling request shows both cycles'
   remaining. Requests in the cycle after today's get the note `After MM/DD/YYYY: N PTO / N Vac` (US) or
   `After MM/DD/YYYY: N` (Manila).
6. Summary for a group's "today": current cycle and next cycle: allotment, adjustments, used, remaining per kind,
   cycle dates, `after_date` = next cycle start. This is what the viewer table and dashboard show.

Acceptance (in `tests/balance_test.php`, hand-rolled runner, no PHPUnit):
- US fixture: all 177 rows reproduce the sheet's stored PTO-remaining and Vacation-remaining values and the 15
  current-cycle balances as of 2026-09-14 (Walker R 2/3, 14/15; Catuto 3/3, 12/15; Ketter 2/3, 10/15; Modzik 3/3,
  13/15; Swafford 1/3, 15/15; Danalewich 3/3, 15/15; Moss 3/3, 0/5; Coleman 3/3, 10/10; Walker J 2/3, 0/5; the six
  2026 hires 0/0). Holiday exclusion is inactive for these rows (section 5), so the numbers are exactly the sheet's.
- Manila fixture: the 18 current/next balances as of 2026-09-14 computed by the sheet's `getDataAsJson` logic with
  the two adjustments anchored (section 9): Myra 1/10, Miguel 6/10 (see note), John Michael 5/10, John Roman Cano
  10/10, Raymond Caspe 10/10 (12 allotted), Jam 2/10, Chona 5/5, Mary Ann 6/10, Eric Codog 0/3, Arlan 3/5, Rei 5/5,
  Dianne 2/5, Rimalyn 0/5, Zel 3/3, Apple 1/3, Reden 0/3, Dayne 0/3, John Michael D 0/3. Note: the live sheet shows
  Miguel 5 because its CURRENT/NEXT adjustment semantics drift with the date; the intent recorded in the sheet's own
  note (+1 for the cycle starting 2026-04-22) gives 6, and that is what the anchored adjustment produces.
- Split rule: Miguel 2024-04-18..2024-04-22 charges 2 days to the 2023-04-22 cycle and 1 day to the 2024-04-22 cycle;
  Rimalyn 2026-04-02..2026-04-08 charges 2 to the 2025 cycle and 3 to the 2026 cycle.
- Edge cases, hand-written: Saturday-only row (0 days), 6-working-day Mon-Mon row, request on the anniversary,
  the day before, negative balances, adjustment on `cycle_start` (in) and on `cycle_end` (out), fractional adjustment,
  Feb 29 hire, Dec 31/Jan 1, a request before hire date (allotment 0, negative), a holiday inside a request before
  and after `holidays_excluded_from`, a two-day holiday spanning a weekend.
- `tools/balance_oracle.py` and `tools/manila_oracle.py` (the Python re-implementations that produced the numbers
  above) stay in the repo as independent implementations.

## 5. Company holidays

- `events.is_holiday = 1` marks an event as "office closed, not charged against PTO". It belongs to the group of its
  calendar. The events form shows it as a checkbox labelled "Company holiday (does not count against PTO)".
- The engine excludes a weekday from a request's consumed days when it falls inside a holiday event of the
  employee's group **and** the request's `start_date >= groups.holidays_excluded_from`. `NULL` means the rule is off.
  The importer sets it to the import date for both groups; Admin can change it. Reason: 26 existing US rows overlap
  the 2024 and 2025 Christmas breaks and were charged; applying the rule from a date keeps history and the acceptance
  test exact while every new request benefits.
- Import flags `is_holiday` where the title matches `/holiday|company break|office closed|christmas break/i`;
  parties, luncheons, sales and factory closings stay unflagged. The migration report lists what was flagged.
- The time-off form's live preview says e.g. `3 working days (Dec 25 is a company holiday, not charged)`.

## 6. Data model (`migrations/001_init.sql`)

```sql
CREATE TABLE groups (
  id           TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  group_key    VARCHAR(20) NOT NULL UNIQUE,            -- 'us', 'manila'
  name         VARCHAR(80) NOT NULL,                   -- 'Lightsaber Promotions', 'Bright Bird Design'
  timezone     VARCHAR(40) NOT NULL,                   -- 'America/New_York', 'Asia/Manila'
  policy_key   VARCHAR(20) NOT NULL,                   -- 'us' | 'manila' (lib/policy.php)
  holidays_excluded_from DATE NULL,                    -- section 5; NULL = rule off
  viewer_title    VARCHAR(80) NOT NULL,                -- browser title of the viewer page
  viewer_heading  VARCHAR(120) NOT NULL,               -- h1 on the viewer page
  viewer_embed_src TEXT NULL,                          -- full Google Calendar embed URL (kept verbatim from the old pages)
  viewer_password_hash VARCHAR(255) NULL,              -- password_hash(); NULL = page disabled
  viewer_password_version INT UNSIGNED NOT NULL DEFAULT 1,  -- bumped on password change; invalidates trust cookies
  sort_order   TINYINT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employees (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id      TINYINT UNSIGNED NOT NULL,
  name          VARCHAR(80) NOT NULL,                  -- exact display name and calendar title prefix:
                                                       -- US "Walker, Rebecca" (form composes Last, First), Manila "Myra"
  hire_date     DATE NOT NULL,
  birth_month   TINYINT UNSIGNED NULL,
  birth_day     TINYINT UNSIGNED NULL,
  birth_year    SMALLINT UNSIGNED NULL,                -- NULL for placeholder years (>= 2023 in the US sheet)
  status        ENUM('active','departed') NOT NULL DEFAULT 'active',
  departed_on   DATE NULL,
  notes         VARCHAR(255) NULL,
  legacy_row    INT UNSIGNED NULL,
  created_at DATETIME NOT NULL, created_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL, updated_by INT UNSIGNED NULL,
  CONSTRAINT fk_emp_group FOREIGN KEY (group_id) REFERENCES groups(id),
  CONSTRAINT ck_emp_departed CHECK (status = 'active' OR departed_on IS NOT NULL),
  KEY ix_emp_group_name (group_id, name)               -- not unique: a rehire is a new row (Eric Codog is one)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE time_off (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT UNSIGNED NOT NULL,
  kind          ENUM('PTO','Vacation') NOT NULL,       -- must be in the group's policy kinds
  start_date    DATE NOT NULL,
  end_date      DATE NOT NULL,                         -- inclusive
  note          VARCHAR(255) NULL,
  legacy_row    INT UNSIGNED NULL,
  google_event_id VARCHAR(120) NULL, synced_fingerprint CHAR(40) NULL, sync_error VARCHAR(300) NULL,   -- M2
  created_at DATETIME NOT NULL, created_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL, updated_by INT UNSIGNED NULL,
  CONSTRAINT fk_to_emp FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT ck_to_range CHECK (end_date >= start_date),
  KEY ix_to_emp_start (employee_id, start_date, id), KEY ix_to_start (start_date), KEY ix_to_gid (google_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- No unique key on (employee, start, end): the sheets allowed duplicates and a weekend-only row exists. The form warns.

CREATE TABLE adjustments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id    INT UNSIGNED NOT NULL,
  kind           ENUM('PTO','Vacation') NOT NULL,
  effective_date DATE NOT NULL,                        -- selects the cycle: cycle_start <= effective_date < cycle_end
  days           DECIMAL(5,2) NOT NULL,                -- + grant, - deduct
  reason         VARCHAR(255) NOT NULL,
  legacy_row     INT UNSIGNED NULL,
  created_at DATETIME NOT NULL, created_by INT UNSIGNED NULL,
  CONSTRAINT fk_adj_emp FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT ck_adj_days CHECK (days <> 0),
  KEY ix_adj_emp (employee_id, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE calendars (
  cal_key       VARCHAR(30) NOT NULL PRIMARY KEY,      -- 'us_pto','us_birthdays','us_holidays','us_factory','us_sales',
                                                       -- 'mn_pto','mn_birthdays','mn_events','mn_factory','mn_other'
  group_id      TINYINT UNSIGNED NOT NULL,
  label         VARCHAR(60) NOT NULL,
  kind          ENUM('pto','birthdays','events') NOT NULL,
  google_calendar_id VARCHAR(160) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sync_enabled  TINYINT(1) NOT NULL DEFAULT 1,
  dirty         TINYINT(1) NOT NULL DEFAULT 0,
  last_sync_at DATETIME NULL, last_sync_ok TINYINT(1) NULL, last_sync_message VARCHAR(300) NULL,
  sort_order    TINYINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_cal_group FOREIGN KEY (group_id) REFERENCES groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE events (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cal_key       VARCHAR(30) NOT NULL,                  -- an 'events'-kind calendar; its group owns the event
  title         VARCHAR(200) NOT NULL,
  start_date    DATE NOT NULL,
  end_date      DATE NOT NULL,                         -- inclusive
  is_holiday    TINYINT(1) NOT NULL DEFAULT 0,         -- section 5
  legacy_row    INT UNSIGNED NULL,
  google_event_id VARCHAR(120) NULL, synced_fingerprint CHAR(40) NULL, sync_error VARCHAR(300) NULL,
  created_at DATETIME NOT NULL, created_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL, updated_by INT UNSIGNED NULL,
  CONSTRAINT fk_ev_cal FOREIGN KEY (cal_key) REFERENCES calendars(cal_key),
  CONSTRAINT ck_ev_range CHECK (end_date >= start_date),
  KEY ix_ev_cal_start (cal_key, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE birthday_events (                         -- M2 uses it; created now so the schema is complete
  employee_id     INT UNSIGNED NOT NULL,
  year            SMALLINT UNSIGNED NOT NULL,
  google_event_id VARCHAR(120) NULL, synced_fingerprint CHAR(40) NULL, sync_error VARCHAR(300) NULL,
  PRIMARY KEY (employee_id, year),
  CONSTRAINT fk_bd_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(120) NOT NULL UNIQUE,          -- login name; any address
  display_name  VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','editor') NOT NULL DEFAULT 'editor',
  group_id      TINYINT UNSIGNED NULL,                 -- NULL = all groups; set = editor limited to that group
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL,
  CONSTRAINT fk_user_group FOREIGN KEY (group_id) REFERENCES groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (                               -- append-only
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  at            DATETIME NOT NULL,
  user_id       INT UNSIGNED NULL,                     -- NULL = import or cron
  ip            VARCHAR(45) NULL,
  action        ENUM('insert','update','delete','restore','login','login_failed','viewer_login_failed','import','sync','setting') NOT NULL,
  table_name    VARCHAR(30) NULL,
  row_id        INT UNSIGNED NULL,
  employee_id   INT UNSIGNED NULL,
  group_id      TINYINT UNSIGNED NULL,
  before_json   MEDIUMTEXT NULL,
  after_json    MEDIUMTEXT NULL,
  summary       VARCHAR(255) NOT NULL,
  KEY ix_audit_emp (employee_id, at), KEY ix_audit_at (at), KEY ix_audit_row (table_name, row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  name  VARCHAR(40) NOT NULL PRIMARY KEY,
  value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- keys: schema_version, engine_version, sync_mode ('dry_run'|'live'), google_token_cache, google_token_expires_at,
--       consecutive_sync_failures, last_alert_sent_at,
--       misfiled_events (JSON list of events.id the importer flagged "possibly misfiled"; the Events screen clears an id when the row is moved),
--       misfiled_dismissed (JSON list of events.id whose "possibly misfiled" flag was dismissed with "Keep here" on the Events screen)
```

Seed rows (in the migration):

```sql
INSERT INTO groups (id, group_key, name, timezone, policy_key, viewer_title, viewer_heading, sort_order) VALUES
 (1, 'us',     'Lightsaber Promotions', 'America/New_York', 'us',     'Lightsaber PTO Calendar', 'Lightsaber Vacation/PTO Calendar', 1),
 (2, 'manila', 'Bright Bird Design',    'Asia/Manila',      'manila', 'Manila PTO Calendar',     'Bright Bird Design- PTO Calendar', 2);
-- viewer_embed_src for each group = the exact iframe src from reference/viewer_pages.md (set by the migration too).

INSERT INTO calendars (cal_key, group_id, label, kind, google_calendar_id, sort_order) VALUES
 ('us_pto',       1, 'PTO / Vacation',            'pto',       '0ec358900fdb35e7d48a00c7930be8eff9a8599d9cc6f1a47a13de9b044f34a9@group.calendar.google.com', 1),
 ('us_birthdays', 1, 'Birthdays',                 'birthdays', '8579146e437a950aaa8b704aab72515388e5dd18eaa7d086167f02810f776bd8@group.calendar.google.com', 2),
 ('us_holidays',  1, 'Company Events & Holidays', 'events',    '4df8b9e4839d04a9f35bc520a86e85cc3b8856d8df185f2a0aef8cf69007f63c@group.calendar.google.com', 3),
 ('us_factory',   1, 'Factory Closings',          'events',    '470bb84a2fadbdb4c543cc0676dc1e0e5bd413cf7ff41b39eb80b0333909db56@group.calendar.google.com', 4),
 ('us_sales',     1, 'End of Month Sales',        'events',    'e96813af5476467dd32b9eab64982306f0409967ec4610be387aabdcdc899a70@group.calendar.google.com', 5),
 ('mn_pto',       2, 'PTO',                       'pto',       '65b050c41850c1af97e861504ab8436e24bdef1ca482b751d4e1f1ac1e482f06@group.calendar.google.com', 1),
 ('mn_birthdays', 2, 'Birthdays',                 'birthdays', 'cc0e9abcf67ee5c55a76a74adc0da6baf2d06712880a62bbe5d5c6b992a3f807@group.calendar.google.com', 2),
 ('mn_events',    2, 'Events & Holidays',         'events',    'b7e4ad952770945c47c705a3729928e46035a2855fc382688c807a0e90e4046e@group.calendar.google.com', 3),
 ('mn_factory',   2, 'Office Closings',           'events',    'b734044eb0c6bd61be019b189d46ccc7c8a8be9872104e24df141ce115e1ceca@group.calendar.google.com', 4),
 ('mn_other',     2, 'Other (unmanaged)',         'events',    'e2961e294c61fd6cf99977bc42f5da79b17ecbe15e84f7116ac379aaa1028f59@group.calendar.google.com', 5);
UPDATE calendars SET sync_enabled = 0 WHERE cal_key = 'mn_other';   -- nothing in the old script wrote to it; ask Chris
INSERT INTO settings (name, value) VALUES ('schema_version','1'), ('engine_version','1.0.0'), ('sync_mode','dry_run');
```

## 7. Screens (Milestone 1)

Roles (the `users.role` ENUM values stay `admin` / `editor`; the UI shows them through `role_label()`):
`admin` = **Master admin** (everything), `editor` = **Admin** (full data entry across the calendars, optionally limited
to one group; no Admin or History tab, and `admin.php` / `history.php` answer 403), `viewer` = the viewer pages (no
account; open or password-protected per group, section 8). Every logged-in screen has a group switcher (tabs
"Lightsaber Promotions | Bright Bird Design") that sets `?g=` and remembers it in the session; an Admin limited to a
group sees only theirs.

0. **Viewer page** `view.php?g=us|manila` [open, or password per group]. Same layout and wording as the old pages: heading, the Google
   Calendar embed iframe (`groups.viewer_embed_src`, height 700), and the table. US: "Remaining Days After Scheduling
   Time Off": Employee | Hire Date | PTO | VAC. Manila: "Remaining Time Off": Employee | Hire Date | PTO (Current)
   with the small "After MM/DD/YYYY: N" line when next-cycle usage exists. Both: a "cycle renews MM/DD" hint under
   the hire date. Active employees only. Server-rendered from the engine using the group's today. `X-Robots-Tag: noindex`.
   When the group requires the office password (section 8) and the device is not trusted yet: a minimal form (one
   password field, "Remember this device" always on) styled like the page. Wrong password: 1-second delay, audited
   with IP. When the group is open (the default) the same page renders with no form and no cookie. Neither page ever
   shows the other group.
1. **Login** `login.php` [master admin / admin]. Email + password. Failed login: half-second delay, audited. `must_change_password` forces a change.
2. **Dashboard** `dashboard.php` (also `index.php` -> redirect) [both]. For the selected group: one row per active employee:
   name, hire date, years of service, current cycle dates, per kind "left / allotment", Total, next-cycle preview
   ("After 03/08/2027: 3 PTO / 15 Vac"). Red if negative, amber if 1 or fewer. Upcoming: Today / This week / Next week /
   Later this month (time off, birthdays, events) for the group. Sync status strip placeholder ("Calendar sync: not
   configured" in M1). Buttons: + Time off, + Employee. Toggle: show former employees.
3. **Time off form** `request.php` [both]. Employee select (active, current group, last-used first), kind as segmented
   buttons (only the group's kinds; Manila shows one), start date, end date auto-filled, note. Live preview line via
   `preview.php` (session-checked JSON: working days, holidays skipped, resulting balance, warnings). Non-blocking
   warnings: zero working days; balance goes negative; overlaps an existing request; straddles an anniversary (US: offers
   "split into two rows"; Manila: says how many days go to each cycle); includes a company holiday (US/M: "not charged");
   before hire date; after departure date. Save keeps the employee selected for quick repeated entry. Edit mode via `?id=`.
4. **Time off list** `requests.php` [both]. Newest first; filters employee, kind, cycle year; columns like the sheet:
   employee, kind, start, end, working days, remaining after (per kind), note, calendar state (M1: "not synced").
   Edit, delete (confirm; full before-image to audit_log). Export CSV in the sheet's column order.
5. **Employees** `employees.php` [both]. Active list with hire date, years of service, birthday MM/DD, allotments, Total.
   + Add employee: US form has Last name + First name fields composing "Last, First" (live preview); Manila has one Name
   field. Hire date; birthday month/day dropdowns, optional year. Warns on duplicate name (active or former).
   **Former employees** tab: departed people with hire/departure dates and a link to their ledger; Export CSV per person.
6. **Employee detail** `employee.php?id=N` [both]. Profile edit (hire-date change shows a before/after balance table
   per cycle and needs a confirm checkbox; name change warns that calendar titles will change). Cycle-by-cycle ledger
   from hire date through next cycle: allotment, adjustments (with reasons), used, remaining, every request with its
   running balance. Adjustments panel (+ grant/deduct, kind, effective date with the resulting cycle shown, mandatory
   reason). "Mark as departed on <date>" (M1: sets status; M2 adds the calendar deletes). Un-depart.
7. **Events** `events.php?g=us&cal=us_holidays|us_factory|us_sales` (Manila: `mn_events|mn_factory|mn_other`) [both].
   One tab per events calendar of the group. Inline add row (title, start, end, holiday checkbox), edit/delete per row,
   "Copy last year's events to <next year>". Rows imported onto an odd sheet (the 2023 party/luncheon/sale rows on the
   US Factory Closings sheet; the 2023-24 US office closures on the Manila Factory Closings sheet) are flagged
   "possibly misfiled" with a one-click move to another calendar of the same group.
8. **History** `history.php` [master admin only; an Admin gets 403 and no nav link]. Audit log with filters (group, employee, table, user, date),
   before/after, and Restore for deleted time-off and event rows.
9. **Admin** `admin.php` [master admin only]. Users (add, role Master admin / Admin, group limit, temporary password,
   deactivate; the last active master admin cannot be demoted or deactivated). Groups: "Require the office password on
   the viewer page" checkbox (section 8), viewer password (set/change; bumps version), `holidays_excluded_from`,
   viewer heading/title, embed URL. Calendars: the ten rows with
   IDs and active/sync toggles (sync buttons are M2). Import (upload the JSON snapshot or CSVs, dry run, commit). Self-test.
   Footer everywhere: app version, engine version, schema version, PHP version.
10. **Setup** `setup.php?token=` runs once: checks PHP, extensions, `.htaccess`, DB connection; runs `001_init.sql`
    if `settings` is missing; creates the first admin; sets both viewer passwords; prints "delete this file".

## 8. Viewer-page authentication (trust cookie)

- **Per-group switch** in `settings`: `viewer_public_<group_key>` = `'1'` means the viewer page is open (no password,
  no cookie); `'0'` means the office password below is required. Key absent = open (the current default).
  `viewer_requires_password(array $group): bool` (lib/viewer_auth.php) reads it; `view.php` skips the whole gate when it
  is false and renders exactly the same page (still no nav, still `noindex`). Admin > Groups writes it through the
  "Require the office password on the viewer page" checkbox (audited as `setting`); switching it never clears the stored
  hash, so the gate can be turned back on without a new password. Checked with no password set = the page answers 404
  until one is set (Admin shows a warning line).
- One shared password per group in `groups.viewer_password_hash` (`password_hash`, `PASSWORD_DEFAULT`).
- On success, set cookie `pto_trust_<group_key>` = `base64url(payload) . '.' . hmac` where payload =
  `group_key|password_version|expires_unix` and hmac = `hash_hmac('sha256', payload, config secret)`.
  Expiry **90 days**; on every visit with more than 30 days elapsed since issue the cookie is re-issued for another 90
  (a sliding window, so a device used at least every three months never asks again). Flags: `Secure` (when https),
  `HttpOnly`, `SameSite=Lax`, path `/`.
- Validation: signature, group, version equals the group's current `viewer_password_version`, not expired. Changing the
  password bumps the version and logs everyone out of that page.
- The password form has no username. Failures sleep 1 second and are written to `audit_log` as `viewer_login_failed`
  with IP. No lockout (a shared 12+ character password on an unlinked URL).
- Admin sessions do not unlock viewer pages and vice versa (a master admin who is not trusted on the device types the
  viewer password once, like everyone else).

## 9. Import (`tools/import_sheet.php`, also Admin > Import)

Input: `reference/sheet_snapshot_2026-09-14.json` (US) and `reference/manila_sheet_snapshot_2026-09-14.json`
(Manila), the openpyxl dumps of the two workbooks (`{"<sheet name>": {"rows": [[...], ...]}}`, first row = headers,
dates as ISO strings). Dry run prints the report; commit writes inside one transaction and runs the acceptance
check (section 4) before commit; any mismatch rolls back and prints the row-level diff.

US mapping: "Employee Start Date" -> employees (group 1; `name` = column A trimmed; birth year kept only if < 2023);
"Time Requested Off" -> time_off (kind normalised: `vacation` -> Vacation; rows in sheet order); "Any Additional Events
Calendar" -> events `us_holidays`; "Factory Closings Calendar" -> `us_factory`; "End Of Month Sale Calendar" -> `us_sales`.

Manila mapping: "Employee Start Date" -> employees (group 2; note Eric Codog's four 2024-25 rows predate his current
hire date 2026-08-31: they are imported as-is, listed as warnings, and belong to a previous stint; the ledger shows them
in cycles before his hire date with allotment 0); "Time Requested Off" -> time_off kind PTO; "PTO Adjustments" ->
adjustments with `effective_date` anchored: `CURRENT` keeps the sheet's effective date; `NEXT` uses the start of the
cycle after the one containing the sheet's effective date (Miguel: 2026-04-22; Raymond: 2026-03-03); reason = the
sheet's note; "Any Additional Events Calendar" -> `mn_events`; "Factory Closings Calendar" -> `mn_factory`.
`holidays_excluded_from` for both groups = the import date.

"Replace all": inside the transaction delete `birthday_events`, `adjustments`, `time_off`, `events`, then `employees`
**of the group being imported** (the other group is untouched), import; `users`, `groups`, `calendars`, `settings`,
`audit_log` untouched; one `audit_log` row records the replacement. Auto-increment counters are not reset (ALTER TABLE
is DDL and would commit the transaction in MariaDB). Titles on a Factory Closings sheet are treated as factory closings
when they match `/factor|chinese|festival|national day|labor day|tomb|dragon/i`; the rest are "possibly misfiled"
(ids kept in `settings.misfiled_events`) and never `is_holiday`-flagged as factory rows.

## 10. Engine API (`lib/balance.php`), pure functions, no DB, no clock

```php
policy_for(string $policyKey): array                    // ['kinds'=>[...], 'straddle'=>'start_cycle'|'split', 'allot'=>callable(int $yos): array<kind,int>]
cycle_start(DateTimeImmutable $hire, DateTimeImmutable $ref): DateTimeImmutable
cycle_end(DateTimeImmutable $cycleStart): DateTimeImmutable          // exclusive
years_of_service(DateTimeImmutable $hire, DateTimeImmutable $at): int
working_days(DateTimeImmutable $start, DateTimeImmutable $end, array $holidayDates /* 'Y-m-d' => true */): int
consumed_by_cycle(array $policy, DateTimeImmutable $hire, array $request, array $holidayDates, ?DateTimeImmutable $holidaysFrom): array  // ['Y-m-d cycle_start' => days]
ledger(array $policy, array $employee, array $requests, array $adjustments, array $holidayDates, ?DateTimeImmutable $holidaysFrom, DateTimeImmutable $today): array
   // cycles in order from hire date through next cycle (and any cycle a request or adjustment touches), each:
   // ['start','end','yos','allotment'=>[kind=>n],'adjustments'=>[...],'used'=>[kind=>n],'remaining'=>[kind=>n],
   //  'requests'=>[ ['id','kind','start','end','days','days_in_cycle','remaining_after'=>[kind=>n],'note_after'=>?string], ...]]
summary(array $policy, array $employee, array $requests, array $adjustments, array $holidayDates, ?DateTimeImmutable $holidaysFrom, DateTimeImmutable $today): array
   // ['current'=>['start','end','allotment','used','remaining'], 'next'=>[...], 'after_date'=>'MM/DD/YYYY']
```
All dates in/out are `DateTimeImmutable` at midnight; requests/adjustments are plain arrays with string dates.
`ENGINE_VERSION = '1.0.0'` constant.

## 11. Audit

`audit(string $action, ?string $table, ?int $rowId, ?int $employeeId, ?int $groupId, ?array $before, ?array $after, string $summary)`
is called inside the same transaction as the change. Summaries read like
`Walker, Rebecca: Vacation 2026-10-11..2026-10-13 (3 days) added`.

## 12. Local development

- `run-local.bat`: `C:\xampp\php\php.exe -S 127.0.0.1:8020 -t public_html\pto` (XAMPP's MariaDB must be running;
  the XAMPP control panel or `mysqld --standalone`). Open `http://127.0.0.1:8020/`.
- `pto_data/config.php` locally: `environment = 'local'`, db `pto_local` / user `root` / empty password (XAMPP default).
- `tools/dev_reset.php`: drops and recreates the `pto_local` schema from `001_init.sql`, creates admin
  `chris@lightsaberpromotions.com` / `changeme-now`, sets both viewer passwords to `staff`, imports both fixtures with
  `as_of` = the snapshot date in the fixture file name (2026-09-14), so `holidays_excluded_from` and the acceptance
  check against `expected_2026-09-14.json` stay exact on any day (and even when Manila is already on the next day).
- `ledger()` returns its cycles keyed by `Y-m-d` cycle start (in order); `summary()` returns the two cycle entries.
- `php pto_app/tests/balance_test.php` must print one green line before any upload.

## 13. Out of scope for Milestone 1 (Milestone 2 and 3)

Google Calendar client and sync, adoption/reset tools, departed-employee calendar deletes, birthday regeneration,
cron jobs, backups, alerts, ICS. The schema and the `dirty`/`google_event_id` columns are already in place for them.

## 14. Milestone 2: Google Calendar sync (added 2026-09-16)

Goal: the ten Google Calendars become a read-only mirror of the database, written through the Calendar API v3 with a
service account. Nothing in Milestone 1 changes its behaviour; sync is additive. The company account is a consumer
Gmail, so no Workspace steps exist. Design source: RECOMMENDATION.md section 7, adapted to groups.

### 14.1 Google client (`pto_app/lib/google.php`)
- Credentials: `PTO_DATA/google-service-account.json` (the key file downloaded from Google Cloud). `google_status()`
  reports whether it exists, its `client_email` (the address each calendar must be shared with, "Make changes to events")
  and whether a token can be obtained.
- Token: RS256 JWT (`iss` = client_email, `scope` = `https://www.googleapis.com/auth/calendar.events`,
  `aud` = `https://oauth2.googleapis.com/token`, `iat`, `exp` = iat+3600) signed with `openssl_sign(OPENSSL_ALGO_SHA256)`,
  exchanged with `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer` over curl. Cached in `settings`
  (`google_token_cache`, `google_token_expires_at`) for 55 minutes.
- `google_request(string $method, string $path, ?array $query = null, ?array $body = null): array` (path relative to
  `https://www.googleapis.com/calendar/v3/`). Retries: 401 -> refresh token once; 403 rateLimitExceeded / 5xx ->
  back off 1, 2, 4, 8 s; other errors throw `GoogleApiError` (properties: status, reason, message). Uses curl only.
- Transport is swappable for tests: `$GLOBALS['google_transport']` is a callable `(method, url, headers, body) ->
  [status, jsonBody]`; the default performs the real curl call. `tests/sync_test.php` installs an in-memory fake
  Calendar API (events.list with privateExtendedProperty and paging, insert, patch, delete, get) keyed by calendar id.
- Write guard: `google_writes_allowed(bool $explicit = false): bool` = `config('environment') === 'production'`
  AND (`settings.sync_mode === 'live'` OR `$explicit`). Locally nothing is ever written. `explicit` is used only by
  Test connection, Force, Adopt, Wipe-and-regenerate birthdays. Reads (events.list) are always allowed.
- Event shape (all-day): `summary`, `start.date`, `end.date` (exclusive = inclusive end + 1 day),
  `extendedProperties.private.lsp = <key>`, `description` "Birthday" for birthdays. Google generates ids.
- Helpers: `google_list_events(string $calendarId, ?string $lspKey = null): array` (singleEvents=true,
  timeMin 2021-01-01, timeMax 2040-01-01, maxResults 2500, fields limited to id/summary/start/end/extendedProperties/status,
  follows nextPageToken), `google_insert_event`, `google_patch_event`, `google_delete_event` (404 on delete = done),
  `google_get_event`.

### 14.2 Desired set (`pto_app/lib/sync.php`)
Per calendar (`calendars.cal_key`), from the database only:

| kind | rows | key (`lsp`) | title | dates |
|---|---|---|---|---|
| pto | `time_off` of ACTIVE employees of the calendar's group | `time_off:<id>` | `<employee name> - <kind>` (US: "Walker, Rebecca - Vacation"; Manila: "Myra - PTO") | start, end+1 |
| birthdays | `birthday_events` rows whose employee is ACTIVE | `birthday:<employee_id>:<year>` | `<employee name> Birthday` | date(year, month, day), Feb 29 -> Mar 1 in non-leap years; +1 |
| events | `events` with that `cal_key` | `event:<id>` | title as typed | start, end+1 |

Fingerprint = sha1(title|start|end_exclusive). `birthday_events` rows for year Y and Y+1 (Y = the group's today) are
topped up for every active employee with a month and day by `birthday_rows_topup(int $groupId)` (called by the nightly job
and after every employee save). A departed employee's `birthday_events` rows are deleted when their events are deleted.

### 14.3 Algorithm
`sync_calendar(string $calKey, string $mode /* 'incremental'|'reconcile' */, array $opts = ['dry_run'=>bool,'force'=>bool,'trigger'=>string]): array`
returns `['ok'=>bool,'plan'=>[...],'executed'=>[...],'message'=>string,'aborted'=>?string]`. Guarded by MySQL
`GET_LOCK('pto_sync_<cal_key>', 0)` (skip with a message if held).

1. **Departed cleanup** (both modes, first): `time_off` and `birthday_events` rows whose employee is departed and whose
   `google_event_id` is not NULL -> DELETE by id, clear the id (404 counts as done). Exempt from the mass-delete guard.
2. **Incremental**: rows with `google_event_id IS NULL` -> `events.list?privateExtendedProperty=lsp=<key>` first (recover
   a lost insert response), else INSERT; store id + fingerprint, clear `sync_error`. Rows whose fingerprint differs from
   the stored one -> PATCH (404 -> clear id, re-insert next pass). Also (engine addition, so an app delete leaves the
   calendar within 15 minutes instead of at the next nightly run): `audit_log` `delete` rows for this calendar's table
   newer than the cursor in `settings.sync_audit_cursor_<cal_key>` whose before-image carries a `google_event_id` ->
   DELETE that id (skipped when a row with that id and Google id exists again, i.e. it was restored). A complete run of
   either mode advances the cursor; reconcile stays the safety net.
3. **Reconcile** (nightly, Sync now, Preview): one full listing; then (a) remote events with an `lsp` key that no live
   desired row owns -> DELETE only if `audit_log` has a `delete` for that table/row or the owner employee is departed;
   otherwise **orphaned** (reported, never deleted automatically); (b) desired rows whose stored id is not in the
   listing -> clear id and INSERT; (c) remote managed events whose title/dates differ from desired -> PATCH back;
   (d) remote events with no `lsp` property -> **unmanaged** (reported only).
4. **Guards** evaluated on the plan before any write: abort when the desired set for a pto or birthdays calendar is
   empty; abort when planned deletes (departed cleanup excluded) exceed 25, or exceed 20% of the calendar's mirrored
   rows once at least 3 deletes are planned (floor added by the engine: one or two deletes on a five-event calendar are
   an ordinary edit, not a mass delete); when `google_writes_allowed()` is false the plan is computed and logged but
   nothing is written ("dry run"). `force` overrides the delete guard only. Without a key file a dry run plans against
   an empty listing (so Preview shows the full plan locally); a run that would write fails with "not configured".
5. Execute inserts, then patches, then deletes, updating each row after each call (a successful DELETE sets
   `google_event_id` and `synced_fingerprint` to NULL; rows are never removed by the sync). Per-row failures go to
   `sync_error` and the run continues (result "partial"). Append one line to `PTO_DATA/logs/sync.log`
   (`2026-09-16 03:05:12 us_pto reconcile ok inserts=2 patches=0 deletes=1 unmanaged=0 orphaned=0 departed=0 (1.8s) [nightly]`;
   the tag is `ok` / `partial` / `dry-run` / `aborted`, the bracket is the trigger), update
   `calendars.last_sync_at/ok/message` (previews with `dry_run=true` change no state), clear `dirty` and
   `settings.dirty_since_<cal_key>` when the run completed with nothing skipped.

- `mark_dirty(string $calKey)`; `sync_dirty_inline(int $groupId, float $budgetSeconds = 10.0)` runs incremental passes
  on the group's dirty calendars within the budget and returns a one-line status for the flash message ("Calendar
  updated." / "Calendar will update within 15 minutes." / "Calendar sync is in dry-run mode." / "Calendar sync is not
  configured." / `''` when nothing is dirty). `mark_dirty` records the first dirty time in `settings.dirty_since_<cal_key>`.
- Hooks: after every time_off insert/update/delete -> mark_dirty(group pto) + inline; after every events write ->
  mark_dirty(that cal_key) + inline; after employee save (name/birthday) -> birthday_rows_topup + mark_dirty(pto and
  birthdays); after restore from History -> mark_dirty. **Mark as departed** additionally runs
  `sync_depart_employee(int $employeeId): array` right away: deletes every event of that employee by stored id (pto rows and
  birthday rows), clears ids, deletes the employee's `birthday_events` rows; leftovers are finished by step 1 later.
  **Un-depart** -> `birthday_rows_topup` + mark_dirty both calendars (rows have NULL ids so they re-insert).
- `sync_reset_state(?int $groupId = null)`: NULL every `google_event_id`/`synced_fingerprint`/`sync_error` on
  `time_off`, `events`, `birthday_events` (optionally one group), clear `calendars.last_sync_*`. Audited.
- `sync_adopt(string $calKey, bool $commit): array` (pto and events calendars only): refuses if any row of that calendar
  already has a `google_event_id`; lists remote events without `lsp`; matches a desired row by exact fingerprint, then
  by same dates and case-insensitive trimmed title; a match stores the id, PATCHes `extendedProperties.private.lsp`
  and the canonical title; reports matched / unmatched-remote / unmatched-rows. Explicit write.
- `sync_wipe_regenerate_birthdays(string $calKey, bool $commit)`: deletes EVERY event on that birthday calendar, tops up
  rows, inserts Y and Y+1 for active employees. Explicit write; one confirmation in the UI.
- `sync_test_connection(string $calKey)`: insert a throwaway all-day event dated 2000-01-01 titled "LSP connection test"
  (with `lsp = probe`), then delete it; OK only if both succeed. Explicit write. Locally reports "not available in local
  environment" and does an events.list instead.
- Alerts (`pto_app/lib/alerts.php`): `alert(string $subject, string $body)` sends with PHP `mail()` to `config alert_email`
  from `alert_from`, at most once per day (`settings.last_alert_sent_at`); raised after 3 consecutive failed runs of any
  calendar (`settings.consecutive_sync_failures`) and by the nightly self-test failure. `alert_test()` for Admin.

### 14.4 Cron (`pto_app/cron/`)
- `sync.php` every 15 minutes: for each active calendar with `sync_enabled=1` and `dirty=1`, incremental.
- `nightly.php` at 03:05: reconcile every active enabled calendar; `birthday_rows_topup` for each group; run the
  balance self-test and alert on failure; write `PTO_DATA/backups/pto-YYYY-MM-DD.json` (employees, time_off,
  adjustments, events, groups, engine_version); trim `sync.log` and `cron.log` entries older than 90 days; keep 60 daily
  JSON exports plus the first of each month. (mysqldump stays a separate cron line, Milestone 3.)
- Both print `PHP_VERSION` and a one-line summary, exit non-zero on failure, and refuse to run twice concurrently.

### 14.5 UI
- Dashboard status strip per group: "All N calendars in sync, last run 12 min ago" / "Calendar sync is in dry-run
  mode" / red banner naming the failing calendar or any calendar dirty for 30+ minutes / "Google not configured".
- Time off list and events tables: Calendar column = synced (green), pending (amber, NULL id or dirty), error (red, with
  the `sync_error` text as the title attribute).
- Admin > Calendars: Google setup status (key file present, client_email to share with, token OK); global `sync_mode`
  switch (dry_run / live) with an explanation; per calendar: Test connection, Preview plan (reconcile dry run: counts
  plus the first 50 of each list), Sync now (reconcile), Force (re-runs the plan ignoring the delete guard), Adopt
  existing events (pto/events; dry run then commit), Unmanaged events list with "adopt as..." (when a single row matches)
  and explicit delete checkboxes, Orphaned events with re-adopt/delete per event, Wipe and regenerate birthdays
  (birthday calendars; confirmation), last run result and the last 100 lines of sync.log; global: Reset sync state
  (confirmation), Send test alert.
- Employee detail: Mark as departed shows the calendar delete result; the ledger shows each request's calendar state.

### 14.6 Tests
`tests/sync_test.php` (hand-rolled, uses the fake transport and a scratch database `pto_local_synctest` created from
`001_init.sql` and dropped by the test): initial insert of all rows; patch after a title change; delete after a request
delete (audit row present) and NOT after an orphan (no audit row); departed cleanup and un-depart re-insert without
duplicates; birthday top-up, Feb 29 handling, wipe-and-regenerate; adoption matching (exact and case-insensitive) and
refusal when ids exist; both guards; dry-run writes nothing; lost-insert recovery via privateExtendedProperty; 404 on
patch -> re-insert; the environment guard blocks writes locally (the test sets a fake 'production' environment in
`$GLOBALS['config']` to exercise the write paths).

### 14.7 Engine implementation notes (2026-09-16)
What the engine (`lib/google.php`, `lib/sync.php`, `lib/alerts.php`, `cron/`, `tools/sync_cli.php`, `tests/sync_test.php`)
does beyond or slightly differently from 14.1-14.4; the UI is written against these signatures.

- Signatures: `google_status(bool $probeToken = true)` (false = no network round trip, for the dashboard);
  `google_insert_event / google_patch_event / google_delete_event(..., bool $explicit = false)` check the write guard
  themselves and throw `GoogleApiError(0, 'writes_disabled', ...)` when refused; `google_event_body($title, $start,
  $endExclusive, $lspKey, ?$description)`. `sync_calendar()` also returns `dry_run`, `mode`, `cal_key`, `seconds`;
  `executed` = `inserts, patches, deletes, departed_cleanup, touched, failed, skipped, errors[], needs_pass`. Plan items carry
  `key, title, start, end, google_event_id, reason, table, row_id`. `sync_status_for_group()` returns
  `['state','summary','calendars'=>[cal_key => [label, kind, is_active, sync_enabled, last_sync_at, last_sync_ok,
  last_sync_message, minutes_since_sync, dirty, dirty_since, minutes_dirty, state (ok|pending|stale|failed|disabled)]]]`.
  Extra tools: `sync_unmanaged($calKey)`, `sync_adopt_one($calKey, $googleEventId, $rowKey)`, `sync_delete_remote($calKey,
  $googleEventId)`, `sync_log_tail($lines)`; `sync_adopt()` and `sync_wipe_regenerate_birthdays()` also return `committed`.
- Settings keys added (no new columns): `dirty_since_<cal_key>`, `sync_audit_cursor_<cal_key>`.
- Reconcile details: a desired row whose stored id is missing but whose key exists remotely re-adopts that event (no
  duplicate insert; this is how a Reset followed by Sync now re-links everything). Extra remote events with a key a live
  row already owns are reported as orphaned "duplicate of ...", never deleted automatically. A remote `event:<id>` whose row
  now lives on another calendar is deleted as "moved to <cal_key>", its stale id cleared and the new calendar marked dirty
  (the Events screen need not clear ids on a move, but may). A leftover `lsp=probe` is reported as orphaned.
  Departed cleanup also drops `birthday_events` rows of departed employees that never got a Google id.
- Wipe-and-regenerate re-inserts only the Y and Y+1 rows; older birthday rows are left without ids.
- Test connection outside `google_writes_allowed(true)` does a read-only listing and reports "not available in local
  environment" (ok = the listing worked).
- Alerts: outside production nothing is mailed; every alert is appended to `PTO_DATA/logs/alerts.log` either way.
  `alert_send()` bypasses the daily limit (used by `alert_test()`); `alert_note_sync_result()` keeps the failure counter.
- Cron: `nightly.php` tops birthday rows up *before* the reconcile pass; a concurrent second copy exits 0 with "SKIPPED"
  (the run itself is not a failure); reconcile aborts/failures, a failing self-test and a backup error exit 1.
- Test hooks (all `$GLOBALS`, never set by the app): `google_transport`, `google_service_account` (decoded key array),
  `google_sleep` (callable(int)), `sync_log_file`, `alert_log_file`, `alert_mailer` (callable(to, subject, body, headers)).
- `tools/sync_cli.php status | preview <cal> | sync <cal> [--force] | adopt <cal> [--commit] | reset [group] |
  wipe-birthdays <cal> --commit | test-connection <cal> | topup | log [n]`.
