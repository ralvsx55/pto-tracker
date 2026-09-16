# The two current staff viewer pages (as of 2026-09-14)

Both are static HTML files on the lightsaberpromotions.com cPanel account. Every
URL on that domain is 301-redirected to lsp.lightsaberpromotions.com by
`public_html/.htaccess`, except these two prefixes, which are served as static pages.
Each page embeds Google Calendars in an `<iframe>` and fills a table by fetching
the Apps Script `doGet` JSON of its sheet.

## US office: https://lightsaberpromotions.com/timeoff518652351

- Title "Lightsaber PTO Calendar", heading "Lightsaber Vacation/PTO Calendar", favicon `icons8-smiling-sun-32.png`,
  background `summer_02.png` under a white 80% overlay.
- Embed (`ctz=America/New_York`, `showCalendars=0`, `showTabs=0`, title "Lightsaber Calendar"), calendars in this order
  with colours 7986CB, 039BE5, D50000, EF6C00, 7CB342, 3F51B5:
  1. `4df8b9e4...@group.calendar.google.com` Any Additional Events (company holidays, parties)
  2. `8579146e...` Birthdays
  3. `470bb84a...` Factory Closings
  4. `e96813af...` End Of Month Sale
  5. `0ec35890...` PTO / Vacation
  6. `en.usa.official#holiday@group.v.calendar.google.com` Google's public US Holidays calendar
- Table "Remaining Days After Scheduling Time Off": Employee | Hire Date (MM/DD/YYYY) | PTO | VAC,
  from `remainingPTO` / `remainingVacation` (current cycle). Data URL:
  `https://script.google.com/macros/s/AKfycbwMRILGlnwIdiDoO5C818iKuYMI9bmbG-f2eb7fY5eqyB5VwJFwy78RKQtI8uHxHtIy/exec`

## Manila artists (Bright Bird Design): https://lightsaberpromotions.com/manilapto

- Title "Manila PTO Calendar", heading "Bright Bird Design- PTO Calendar", favicon `moon.png`,
  background `manila-city-typography-design-oqgnbn59wwav6kxj.jpg` under a black 50% overlay.
- Embed (`ctz=America/New_York`, `showTitle=0`), calendars in this order with colours 616161, 795548, D81B60, 7CB342, 8E24AA:
  1. `b7e4ad95...` Any Additional Events (script-synced; sheet holds 2023 sale dates)
  2. `e2961e29...` NOT written by the Apps Script; unknown, presumably maintained by hand (Philippine holidays?)
  3. `b734044e...` Factory Closings (script-synced; sheet holds 2023-24 US office closures)
  4. `65b050c4...` PTO
  5. `cc0e9abc...` Birthdays
- Table "Remaining Time Off": Employee | Hire Date | PTO (Current), with a small
  "After MM/DD/YYYY: N" line under the current number when `remainingPTONext` is present.
  Data URL: `https://script.google.com/macros/s/AKfycbzin2pAkpnUJuelKsPC-WW_yIqWBUvxuYavO1t5HoPBdyx5lkwNruicllG3qN_N/exec`
  (fetched with a cache-buster).
