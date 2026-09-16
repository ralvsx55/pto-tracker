-- PTO Tracker migration 002: retire the company-holiday feature (SPEC.md section 5).
-- Events are plain calendar entries again and every day off is PTO or Vacation. No schema change: the
-- events.is_holiday column and groups.holidays_excluded_from stay (the engine keeps the dormant capability);
-- this clears the rows the first import flagged and switches the rule off for both groups.
-- Apply on the server by pasting into phpMyAdmin > SQL; locally tools/dev_reset.php applies every migration in order.

UPDATE events SET is_holiday = 0;
UPDATE groups SET holidays_excluded_from = NULL;
UPDATE settings SET value = '2' WHERE name = 'schema_version';
