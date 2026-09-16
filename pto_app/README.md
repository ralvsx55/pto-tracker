# PTO Tracker: application code

Plain PHP 8.3 + MariaDB. `SPEC.md` at the repo root is the contract; this file is the working notes for
whoever maintains the code. Milestone 1 is complete: engine, importer, auth, layout, login, dashboard,
time-off form + list + preview endpoint, employees + employee detail, events, history, admin, the viewer
page and the setup page, all on the library below.

## Run locally (Windows, XAMPP)

1. Start MariaDB (XAMPP control panel).
2. First time, or whenever you want a clean database:
   `C:\xampp\php\php.exe pto_app\tools\dev_reset.php`
   - writes `pto_data/config.php` from local defaults if it is missing (db `pto_local`, root, no password,
     base_url `http://127.0.0.1:8020/`, random secret and install token)
   - drops and recreates `pto_local` from `migrations/001_init.sql`
   - creates master admin `chris@lightsaberpromotions.com` / `changeme-now`, viewer passwords `staff` for both groups
     (the viewer pages are open until Admin > Groups ticks "Require the office password on the viewer page")
   - imports `tests/fixtures/us_snapshot_2026-09-14.json` and `manila_snapshot_2026-09-14.json` with
     `as_of` = the snapshot date in the file name (so `holidays_excluded_from` and the acceptance check
     against `expected_2026-09-14.json` are exact even when it is already tomorrow in Manila)
3. `run-local.bat` (= `php -S 127.0.0.1:8020 -t public_html\pto`) and open http://127.0.0.1:8020/

## Tests and checks

- `C:\xampp\php\php.exe pto_app\tests\balance_test.php` - the engine acceptance suite (no DB). Must print
  one green `OK: N assertions` line before any upload. It checks all 177 US rows against the sheet's stored
  E/F columns, the 15 US and 18 Manila balances in `expected_2026-09-14.json`, the two Manila split cases,
  and the SPEC section 4 edge cases.
- `C:\xampp\php\php.exe pto_app\tests\sync_test.php` - the calendar sync suite (SPEC 14.6). Creates and drops
  the scratch database `pto_local_synctest`, installs an in-memory fake Calendar API and a generated RSA key,
  pretends to be production + `sync_mode` live so the write paths run, and never touches Google or `pto_local`.
  Refuses to run when `config environment` is `production`. Must also print one green `OK: N assertions` line.
- `C:\xampp\php\php.exe pto_app\tools\sync_cli.php status | preview us_pto | ...` - the sync engine from the
  command line against the real local DB. Locally every write is refused, so `preview` shows the full plan
  ("dry run ... Google not configured: planned against an empty calendar") and changes nothing.
- `python pto_app\tools\balance_oracle.py` (US) and `python pto_app\tools\manila_oracle.py --check` (Manila):
  independent Python implementations that produced the expected numbers.
- `C:\xampp\php\php.exe pto_app\tools\smoke.php http://127.0.0.1:8020/ [page ...]` - logs in as the local
  admin, GETs every page, reports status + any PHP error text, tails `pto_data/logs/error.log`.
- `php -l` on every file; the bootstrap turns warnings/notices into exceptions, so anything sloppy lands in
  `pto_data/logs/error.log` with a reference id instead of on the page.

## Import

```
php pto_app/tools/import_sheet.php --group=us|manila --file=<snapshot.json> [--commit] [--replace-all]
                                   [--as-of=YYYY-MM-DD] [--expected=<expected.json>]
```
Dry run by default: rows are written inside a transaction, the acceptance check runs, the report prints,
the transaction rolls back. `--commit` keeps the rows only when the check passes. `--replace-all` deletes
the group's employees, time off, adjustments, birthday events and events first (scoped to that group; users,
groups, calendars, settings and audit_log untouched). Admin > Import (Screens phase) calls the same
`import_snapshot(int $groupId, array $json, array $opts): array` and prints `$report['lines']`.

Things the importer decides (SPEC section 9 and 5):
- `is_holiday` = title matches `/holiday|company break|office closed|christmas break/i` and the row does not
  look like a factory closing (`/factor|chinese|festival|national day|labor day|tomb|dragon/i`).
- "Possibly misfiled" = a row on a Factory Closings sheet that does not look like a factory closing. The
  events table has no flag column (schema is exact), so the flagged ids are kept in `settings.misfiled_events`
  as a JSON list; the Events screen reads it and clears an id when the row is moved. "Keep here" on the Events
  screen records the id in `settings.misfiled_dismissed` instead (both are JSON lists of `events.id`).
  A misfiled row is never `is_holiday`-flagged, even when its title reads like a holiday (SPEC section 9).
- Manila `NEXT` adjustments are anchored to the start of the cycle after the one containing the sheet's
  effective date; `CURRENT` keeps the sheet's date. Birth years >= 2023 are placeholders and stored as NULL.
- Auto-increment counters are not reset on replace-all (ALTER TABLE would commit the transaction in MariaDB).

## Library contract (`pto_app/lib`)

Every page starts with `require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';`. CLI tools use
`require_once __DIR__ . '/../lib/bootstrap.php';` (once, because tools include each other).

| file | functions |
|---|---|
| bootstrap.php | `PTO_APP`, `PTO_DATA`, `APP_VERSION`, `PTO_CLI`; `config(string $key, $default=null)` (dotted keys), `config_loaded()`; timezone, error handling to `pto_data/logs/error.log` + friendly page, security headers, session (`lsp_pto`, 12 h idle), requires every lib file, `csrf_verify()` on POST |
| helpers.php | `h`, `redirect`, `flash`, `flashes`, `fmt_date`, `fmt_datetime`, `fmt_days`, `signed_days` ("+1.5"), `balance_class` (' neg' / ' low' / ''), `month_names`, `csv_text`, `csv_row` (the one fputcsv call), `app_url` (path-relative: works on any port), `app_abs_url`, `json_out`, `now_str`, `is_post`, `post`, `get`, `ymd`, `to_date`, `client_ip`, `plural` |
| db.php | `db`, `q`, `row`, `rows`, `col`, `insert`, `update_row`, `tx`, `setting`, `setting_set`, `sql_statements`, `apply_sql_file` |
| auth.php | `current_user`, `require_login`, `require_role` ('admin' = master admin; `admin.php` and `history.php` demand it), `role_label` ('admin' -> "Master admin", 'editor' -> "Admin"; the only place the role wording lives), `login`, `logout`, `user_can_group`, `user_create` |
| csrf.php | `csrf_token`, `csrf_field`, `csrf_verify` |
| groups.php | `groups_all`, `group_by_id`, `group_by_key`, `current_group` (`?g=` > session > first allowed), `group_today`, `group_policy`, `group_kinds`, `group_holidays` (memoised per request), `group_holidays_reset` (call after an events write), `group_holidays_from`, `group_calendars` |
| layout.php | `layout_header($title, ['nav'=>bool, 'group_tabs'=>bool, 'css'=>[], 'js'=>[], 'title_suffix'=>bool, 'body_class'=>string])`, `layout_footer()`, `layout_error_page($status, $title, $message, $backHref, $backLabel)` (403/404 pages), `layout_theme_toggle($extraClass)`; nav, group tabs, user/logout + sun/moon toggle, flashes, the "Lightsaber Promotions Inc. (c) <year>" footer (the version line is on Admin only). M2: `sync_badge_state(?$row)` ('synced'/'pending'/'error'/'' for the sortable `data-v`) and `sync_badge(?$row, $showError=false)` (the Calendar column badge; the one helper every screen uses) |
| audit.php | `audit($action, $table, $rowId, $employeeId, $groupId, $before, $after, $summary)` |
| policy.php | `policy_for`, `policy_allotment`, `policy_kind_label` (pure) |
| balance.php | `ENGINE_VERSION`, `cycle_start`, `cycle_end`, `next_cycle_start`, `years_of_service`, `working_days`, `request_working_dates`, `holidays_for_request`, `request_holidays_skipped`, `consumed_by_cycle`, `ledger`, `summary`, `after_note`, `engine_date`, `engine_num`, `engine_fmt` (pure: no DB, no clock) |
| balance_db.php | `employee_with_group`, `employee_requests`, `employee_adjustments`, `employee_ledger`, `employee_ledger_for` (a modified employee row), `employee_summary`, `group_summaries`, `sheet_order` (legacy_row, then id), `cycle_label` ("03/08/2026 - 03/07/2027"), `request_preview` |
| time_off.php | `time_off_days` (working days a row charges), `time_off_summary`, `time_off_insert`, `time_off_update`, `time_off_delete` (each audited inside the caller's transaction) |
| employees.php | `employee_birthday`, `employee_blank_values`, `employee_values_from_row`, `employee_read_profile` (validate the posted name / hire date / birthday / notes), `employee_render_profile_fields` (shared by the add and edit forms) |
| viewer_auth.php | `viewer_requires_password` (settings `viewer_public_<group_key>`: '0' = password required, '1' or absent = open), `viewer_set_public`, `viewer_trusted`, `viewer_issue_cookie`, `viewer_check_password`, `viewer_set_password`, `request_is_https` |
| google.php (M2) | `class GoogleApiError` (`status`, `reason`), `google_key_path`, `google_key`, `google_status($probeToken=true)` (`configured` = key file present and parses, `key_file`, `client_email`, `token_ok`, `error`, `environment`, `sync_mode`, `writes_allowed`, `message`), `google_writes_allowed($explicit=false)`, `google_transport` (honours `$GLOBALS['google_transport']`), `google_access_token`, `google_request($method, $path, $query, $body)` (401 refresh, 403-rate/429/5xx back-off 1-2-4-8 s), `google_event_body`, `google_list_events($calendarId, $lspKey=null)`, `google_insert_event`, `google_patch_event`, `google_delete_event` (404 = done), `google_get_event`. Writes throw `writes_disabled` unless the guard allows them |
| sync.php (M2) | `sync_calendar($calKey, 'incremental'\|'reconcile', $opts)`, `mark_dirty`, `sync_dirty_inline($groupId, $budget=10.0)`, `sync_depart_employee`, `birthday_rows_topup`, `sync_reset_state`, `sync_adopt`, `sync_adopt_one`, `sync_unmanaged`, `sync_delete_remote`, `sync_wipe_regenerate_birthdays`, `sync_test_connection`, `sync_status_for_group` (per calendar: `minutes_dirty`, `minutes_since_sync`, `state`), `sync_log_tail`, `sync_calendar_row`; helpers `sync_desired`, `sync_in_window` (the 2021..2040 listing window: rows outside it are never desired), `sync_plan`, `sync_guard`, `sync_execute`, `sync_fingerprint`, `sync_birthday_date`, `sync_end_exclusive`, `sync_remote_norm`, `sync_parse_key`, `sync_dry_run_reason`, `sync_minutes_since`, `sync_log`. See SPEC 14.3 and 14.7 |
| alerts.php (M2) | `alert($subject, $body)` (once per day), `alert_send` (no limit), `alert_test`, `alert_note_sync_result` (3 consecutive failures -> alert), `alert_log` |

Engine shapes (all dates `DateTimeImmutable` at midnight; input rows are DB rows with `Y-m-d` strings):
- `ledger()` returns cycles **keyed by `Y-m-d` cycle start**, in order, contiguous from the earliest cycle
  a request/adjustment touches (or the hire date) through the cycle after today's. Each cycle:
  `start, end (exclusive), yos, allotment[kind], adjustments[rows], adjusted[kind], used[kind], remaining[kind],
  is_current, is_next, is_future, requests[]`. Each request entry: `id, kind, start, end, note, days,
  days_in_cycle, split, holidays_skipped[], remaining_after[kind], note_after` ("After MM/DD/YYYY: 3 PTO / 15 Vac"
  or "After MM/DD/YYYY: 3" for future cycles). A Manila request that straddles the anniversary appears in both
  cycles with its `days_in_cycle`.
- `summary()` returns `['current' => cycle, 'next' => cycle, 'after_date' => 'MM/DD/YYYY']`.
- `request_preview()` returns `working_days, holidays_skipped, by_cycle, cycles, remaining_after, straddle,
  warnings[], summary_line` or `['error' => ...]` (422 from preview.php).

Coding rules: `declare(strict_types=1)` everywhere, PDO prepared statements only, every output through `h()`,
no inline `<script>`/`<style>`/`onclick` (CSP), every POST form includes `csrf_field()`, dates are `Y-m-d`
strings in the DB and `DateTimeImmutable` in code, timestamps from `now_str()`.

Front-end helpers in `public_html/pto/assets/app.js`: `data-autofocus` / `<form data-autofocus-first>`,
`<form data-confirm="...">`, `<input data-follow="<start input id>">` for end-date auto-fill, and sortable tables:
`<table class="sortable">` with `<th data-sort="text|num|date|month">` sorts the tbody client-side on click
(ascending, then descending; `.sorted-asc` / `.sorted-desc` + `aria-sort` on the header). A cell may carry its sort
value in `data-v` (dates shown as MM/DD/YYYY get `data-v="Y-m-d"`, "2 / 3" gets `data-v="2"`, birthdays `MM-DD`);
blanks sort last; a single-cell placeholder row ("No ...") stays at the bottom and a `data-nosort` row (the Events
add row) stays at the top. Headers without `data-sort` are not clickable. None of the app's tables are paginated
except History, where the sort applies to the page shown.

Theme: `assets/theme.js` is loaded by `layout_header()` in `<head>` before `app.css`. It sets
`<html data-theme="dark|light">` synchronously (dark by default, remembered in `localStorage["pto-theme"]`) and
flips it on a click on any `[data-theme-toggle]` element (the sun/moon button in the top bar, or the fixed one on
pages without a top bar: login, viewer page, setup). `app.css` keeps every colour as a token on `:root` with a
`:root[data-theme="dark"]` override block (`color-scheme: dark` there so native controls follow); page stylesheets
must use the tokens only, never literal colours.
CSS classes in `app.css`: `.card .table-wrap .sortable .theme-toggle(-fixed) .btn .btn-primary .btn-danger .btn-sm .badge(-ok|-warn|-err)
.neg .low .flash-ok .flash-err .form-row .actions .toolbar .segmented .preview .status-strip .viewer .subtabs
.inline-form .actions-cell .filters .pager .inline-warn .inline-err .check .mono` and a global `[hidden]` rule;
per-page files (`dashboard.css`, `employees.css`, `requests.css`, `events.css`, `admin.css`, `view.css`) hold
only what one screen needs. Toolbar pattern on every list screen: `<h1>Page</h1><span class="muted">Group</span>`.

## Milestone 2: calendar sync (engine)

The database is the source of truth; the ten Google Calendars are a mirror written through the Calendar API v3
with a service account (`pto_data/google-service-account.json`, shared with each calendar as "Make changes to
events"). SPEC section 14 is the contract, 14.7 lists the engine's additions. Working notes:

- **Nothing is written unless `google_writes_allowed()`** = `environment === 'production'` AND (`settings.sync_mode`
  is `live` OR the action is explicit: Test connection, Force, Adopt, Wipe-and-regenerate). Locally every run is a
  dry run; without a key file a dry run plans against an empty listing so Preview still shows the full plan.
- **Hooks the screens call** (all in `lib/sync.php`): after a time_off write `mark_dirty('<group pto cal>')` then
  `flash('ok', sync_dirty_inline($groupId))`; after an events write `mark_dirty($calKey)` + inline; after an employee
  save `birthday_rows_topup($groupId)` + `mark_dirty` (pto and birthdays); Mark as departed ->
  `sync_depart_employee($employeeId)` (returns `deleted`, `failed`, `message`); Un-depart -> `birthday_rows_topup` +
  `mark_dirty` both. Moving an event between calendars needs nothing special: the old calendar's next reconcile
  deletes its copy ("moved to ..."), clears the stale id and marks the new calendar dirty.
- **Plan / result shape**: `sync_calendar()` returns `ok, plan{inserts, patches, deletes, unmanaged, orphaned,
  departed_cleanup}, executed{inserts, patches, deletes, departed_cleanup, touched, failed, skipped, errors[], needs_pass},
  message, aborted, dry_run, mode, cal_key, seconds`. Plan items: `key, title, start, end (exclusive), google_event_id,
  reason, table, row_id`. `dry_run => true` in `$opts` is a Preview: no state changes at all (still logged).
- **Guards**: empty desired set on a pto/birthdays calendar; more than 25 deletes, or (from 3 deletes up) more than
  20% of the mirrored rows. `force` lifts the delete guard only (and is an explicit write: in production it runs the
  plan even while `sync_mode` is dry_run). Departed cleanup is exempt. Rows dated outside the listing window
  (2021-01-01 .. 2040-01-01) are never part of the desired set, so a reconcile cannot re-insert them nightly.
- **Plan items and dates**: engine items carry Google's exclusive `end`; every screen shows the inclusive last day
  (`admin_end_inclusive()`), and adopt matching compares the row's end + 1 day with the remote end. A non-preview dry
  run says why in its message ("dry run (environment is local, not production, nothing written): ...").
- **Deletes**: only for rows with an `audit_log` delete (reconcile 3a, and incrementally via the
  `sync_audit_cursor_<cal_key>` setting), departed employees, and moved events. Anything else with an `lsp` key is
  "orphaned" and anything without one is "unmanaged"; both are only reported. Admin deletes them explicitly with
  `sync_delete_remote()` or adopts them with `sync_adopt()` / `sync_adopt_one()`.
- **State**: `calendars.dirty/last_sync_*`, `settings.dirty_since_<cal_key>` (for the 30-minute "stale" banner),
  `settings.google_token_cache/_expires_at`, `consecutive_sync_failures`, `last_alert_sent_at`. `sync_reset_state()`
  forgets every Google id (a following reconcile re-adopts events by key, no duplicates).
- **Logs**: `pto_data/logs/sync.log` (one line per run, `sync_log_tail()`), `alerts.log`, `cron.log` (cron output).
- **Cron** (`pto_app/cron/`): `sync.php` every 15 minutes (incremental on dirty calendars), `nightly.php` at 03:05
  (top-up, reconcile everything, balance self-test, `pto_data/backups/pto-YYYY-MM-DD.json`, log trim, backup pruning).
  Both take a `GET_LOCK` and exit 0 with "SKIPPED" if the previous copy is still running, 1 on any failure.
- **Tools**: `tools/sync_cli.php` (see Tests and checks). **Tests**: `tests/sync_test.php`.

Still Milestone 3: mysqldump cron line, ICS feeds.
