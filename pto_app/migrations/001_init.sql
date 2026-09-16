-- PTO Tracker: initial schema and seed rows (SPEC.md section 6).
-- Applied by tools/dev_reset.php locally and by setup.php on the server.
-- Additive migrations only after this file (002_*.sql ...).

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
--       consecutive_sync_failures, last_alert_sent_at, misfiled_events (JSON list of event ids the importer flagged)

-- Seed rows -----------------------------------------------------------------

INSERT INTO groups (id, group_key, name, timezone, policy_key, viewer_title, viewer_heading, sort_order) VALUES
 (1, 'us',     'Lightsaber Promotions', 'America/New_York', 'us',     'Lightsaber PTO Calendar', 'Lightsaber Vacation/PTO Calendar', 1),
 (2, 'manila', 'Bright Bird Design',    'Asia/Manila',      'manila', 'Manila PTO Calendar',     'Bright Bird Design- PTO Calendar', 2);

-- viewer_embed_src = the exact iframe src from the old static pages (reference/viewer_pages.md).
UPDATE groups SET viewer_embed_src = 'https://calendar.google.com/calendar/embed?height=600&wkst=1&bgcolor=%23ffffff&ctz=America%2FNew_York&showTz=0&showCalendars=0&showTabs=0&title=Lightsaber%20Calendar&src=NGRmOGI5ZTQ4MzlkMDRhOWYzNWJjNTIwYTg2ZTg1Y2MzYjg4NTZkOGRmMTg1ZjJhMGFlZjhjZjY5MDA3ZjYzY0Bncm91cC5jYWxlbmRhci5nb29nbGUuY29t&src=ODU3OTE0NmU0MzdhOTUwYWFhOGI3MDRhYWI3MjUxNTM4OGU1ZGQxOGVhYTdkMDg2MTY3ZjAyODEwZjc3NmJkOEBncm91cC5jYWxlbmRhci5nb29nbGUuY29t&src=NDcwYmI4NGEyZmFkYmRiNGM1NDNjYzA2NzZkYzFlMGU1YmQ0MTNjZjdmZjQxYjM5ZWI4MGIwMzMzOTA5ZGI1NkBncm91cC5jYWxlbmRhci5nb29nbGUuY29t&src=ZTk2ODEzYWY1NDc2NDY3ZGQzMmI5ZWFiNjQ5ODIzMDZmMDQwOTk2N2VjNDYxMGJlMzg3YWFiZGNkYzg5OWE3MEBncm91cC5jYWxlbmRhci5nb29nbGUuY29t&src=MGVjMzU4OTAwZmRiMzVlN2Q0OGEwMGM3OTMwYmU4ZWZmOWE4NTk5ZDljYzZmMWE0N2ExM2RlOWIwNDRmMzRhOUBncm91cC5jYWxlbmRhci5nb29nbGUuY29t&src=ZW4udXNhLm9mZmljaWFsI2hvbGlkYXlAZ3JvdXAudi5jYWxlbmRhci5nb29nbGUuY29t&color=%237986CB&color=%23039BE5&color=%23D50000&color=%23EF6C00&color=%237CB342&color=%233F51B5'
 WHERE group_key = 'us';
UPDATE groups SET viewer_embed_src = 'https://calendar.google.com/calendar/embed?height=600&wkst=1&bgcolor=%23ffffff&ctz=America%2FNew_York&showTz=0&showCalendars=0&showTabs=0&showTitle=0&src=YjdlNGFkOTUyNzcwOTQ1YzQ3YzcwNWEzNzI5OTI4ZTQ2MDM1YTI4NTVmYzM4MjY4OGM4MDdhMGU5MGU0MDQ2ZUBncm91cC5jYWxlbmRhci5nb29nbGUuY29t&src=ZTI5NjFlMjk0YzYxZmQ2Y2Y5OTk3N2JjNDJmNWRhNzliMTdlY2JlMTVlODRmNzExNmFjMzc5YWFhMTAyOGY1OUBncm91cC5jYWxlbmRhci5nb29nbGUuY29t&src=YjczNDA0NGViMGM2YmQ2MWJlMDE5YjE4OWQ0NmNjYzdjOGE4YmU5ODcyMTA0ZTI0ZGYxNDFjZTExNWUxY2VjYUBncm91cC5jYWxlbmRhci5nb29nbGUuY29t&src=NjViMDUwYzQxODUwYzFhZjk3ZTg2MTUwNGFiODQzNmUyNGJkZWYxY2E0ODJiNzUxZDRlMWYxYWMxZTQ4MmYwNkBncm91cC5jYWxlbmRhci5nb29nbGUuY29t&src=Y2MwZTlhYmNmNjdlZTVjNTVhNzZhNzRhZGMwZGE2YmFmMmQwNjcxMjg4MGE2MmJiZTVkNWM2Yjk5MmEzZjgwN0Bncm91cC5jYWxlbmRhci5nb29nbGUuY29t&color=%23616161&color=%23795548&color=%23D81B60&color=%237CB342&color=%238E24AA'
 WHERE group_key = 'manila';

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
