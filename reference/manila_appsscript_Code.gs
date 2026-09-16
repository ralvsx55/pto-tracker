// Copyright 2023 Lightsaberpromotions Inc — Apache 2.0
// Google Apps Script bound to the "BuyLSP Calendar" Google Sheet: the CURRENT
// PTO tracker for the Manila artists (Bright Bird Design). PTO only, no Vacation.
// Saved 2026-09-14 from the version Chris pasted in chat. The legacy flag
// functions (setAnniversaryFlag, setAnniversaryFlagAndCopyValue, the older
// calculateRemainingDays that used column-I locks) are omitted here; they are
// superseded by getDataAsJson / recalculatePtoTwoCycles below, which is what the
// viewer page https://lightsaberpromotions.com/manilapto consumes via doGet.
//
// Sheets:
//   Employee Start Date: A name (first name / nickname), B hire date, C birthday, D PTO (allotment tier, overwritten
//                        with current remaining by recalculatePtoTwoCycles)
//   Time Requested Off:  A employee, B type ("PTO"), C start, D end, E current-cycle remaining (calc), F "After MM/DD/YYYY: N" (calc),
//                        H/I legacy year flags
//   PTO Adjustments:     Employee | Effective Date | Days (+/-) | Apply To (CURRENT|NEXT) | Expires After | Note
//   Factory Closings Calendar, Any Additional Events Calendar: [Event Name, Start, End]
//
// Calendars (all owned by the company Gmail account):
//   PTO        65b050c41850c1af97e861504ab8436e24bdef1ca482b751d4e1f1ac1e482f06@group.calendar.google.com
//   Birthdays  cc0e9abcf67ee5c55a76a74adc0da6baf2d06712880a62bbe5d5c6b992a3f807@group.calendar.google.com
//   Additional b7e4ad952770945c47c705a3729928e46035a2855fc382688c807a0e90e4046e@group.calendar.google.com
//   Factory    b734044eb0c6bd61be019b189d46ccc7c8a8be9872104e24df141ce115e1ceca@group.calendar.google.com
//   The viewer page also embeds e2961e294c61fd6cf99977bc42f5da79b17ecbe15e84f7116ac379aaa1028f59@group.calendar.google.com,
//   which no function in this script writes to (unknown / manually maintained).

function doGet(e) {
  try {
    return ContentService.createTextOutput(getDataAsJson()).setMimeType(ContentService.MimeType.JSON);
  } catch (error) {
    return ContentService.createTextOutput(JSON.stringify({ error: String(error), message: error && error.message }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

// Per-employee current and next cycle balances. THE authoritative logic for the viewer page.
function getDataAsJson() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var tz = ss.getSpreadsheetTimeZone();
  var sheetReq = ss.getSheetByName('Time Requested Off');
  var sheetEmp = ss.getSheetByName('Employee Start Date');
  var sheetAdj = ss.getSheetByName('PTO Adjustments'); // optional

  var req = sheetReq.getRange(2, 1, Math.max(0, sheetReq.getLastRow() - 1), 4).getValues(); // A..D
  var emp = sheetEmp.getRange(2, 1, Math.max(0, sheetEmp.getLastRow() - 1), 2).getValues(); // A..B

  // Adjustments: Employee | Effective Date | Days | Apply To | Expires After | Note
  var adjustments = [];
  if (sheetAdj && sheetAdj.getLastRow() >= 2) {
    var adjData = sheetAdj.getRange(2, 1, sheetAdj.getLastRow() - 1, 6).getValues();
    adjData.forEach(function(r) {
      var name = String(r[0] || '').trim();
      var eff  = r[1];
      var days = parseFloat(r[2]);
      var applyTo = String(r[3] || 'CURRENT').trim().toUpperCase(); // CURRENT|NEXT  (relative to TODAY, so it drifts)
      var expires = r[4];
      var note = String(r[5] || '').trim();
      if (!name || !isValidDate(eff) || isNaN(days) || days === 0) return;
      adjustments.push({ employee: name, effective: stripTime(eff), days: days,
        applyTo: (applyTo === 'NEXT') ? 'NEXT' : 'CURRENT',
        expiresAfter: isValidDate(expires) ? stripTime(expires) : null, note: note });
    });
  }

  var employees = {};
  emp.forEach(function(r) {
    var name = String(r[0] || '').trim();
    var hire = r[1];
    if (!name || !isValidDate(hire)) return;
    employees[name] = { employee: name, anniversaryDate: stripTime(hire), remainingPTOCurrent: 0, remainingPTONext: 0, afterDate: null,
      _usedCur: 0, _usedNext: 0, _entCur: 0, _entNext: 0, _curStart: null, _curEnd: null, _nextStart: null, _nextEnd: null };
  });

  var today = stripTime(new Date());

  Object.keys(employees).forEach(function(name) {
    var hire = employees[name].anniversaryDate;
    var curStart = cycleStartFor(hire, today);
    var curEndEx = addYears(curStart, 1);
    var nextStart = curEndEx;
    var nextEndEx = addYears(nextStart, 1);
    employees[name]._curStart = curStart; employees[name]._curEnd = curEndEx;
    employees[name]._nextStart = nextStart; employees[name]._nextEnd = nextEndEx;
    employees[name].afterDate = formatMDY(nextStart);
    employees[name]._entCur  = entitlementFor(hire, curStart)  + sumAdjustments(name, curStart, curEndEx, 'CURRENT');
    employees[name]._entNext = entitlementFor(hire, nextStart) + sumAdjustments(name, nextStart, nextEndEx, 'NEXT');
  });

  // Usage: working-day OVERLAP of each request with each cycle window (a request that
  // straddles the anniversary is SPLIT across the two cycles — different from the US sheet).
  req.forEach(function(r) {
    var employee = String(r[0] || '').trim();
    var type = String(r[1] || '').trim().toLowerCase();
    var start = r[2], end = r[3];
    if (!employees[employee]) return;
    if (type && type !== 'pto') return;
    if (!isValidDate(start) || !isValidDate(end)) return;
    start = stripTime(start); end = stripTime(end);
    var e = employees[employee];
    var usedCur  = workingDaysOverlap(start, end, e._curStart,  new Date(e._curEnd.getTime() - 1));
    var usedNext = workingDaysOverlap(start, end, e._nextStart, new Date(e._nextEnd.getTime() - 1));
    if (usedCur > 0)  e._usedCur  += usedCur;
    if (usedNext > 0) e._usedNext += usedNext;
  });

  Object.keys(employees).forEach(function(name) {
    var e = employees[name];
    e.remainingPTOCurrent = e._entCur - e._usedCur;
    e.remainingPTONext    = e._entNext - e._usedNext;
  });

  var out = Object.keys(employees).sort().map(function(name) {
    return { employee: employees[name].employee, anniversaryDate: employees[name].anniversaryDate,
      remainingPTO: employees[name].remainingPTOCurrent, remainingPTOCurrent: employees[name].remainingPTOCurrent,
      remainingPTONext: employees[name].remainingPTONext, afterDate: employees[name].afterDate };
  });
  return JSON.stringify(out);

  function isValidDate(d) { return (d instanceof Date) && !isNaN(d); }
  function stripTime(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function addYears(d, n) { return new Date(d.getFullYear() + n, d.getMonth(), d.getDate()); }
  function cycleStartFor(hireDate, todayDate) {
    var candidate = new Date(todayDate.getFullYear(), hireDate.getMonth(), hireDate.getDate());
    if (todayDate.getTime() < candidate.getTime()) candidate = new Date(todayDate.getFullYear() - 1, hireDate.getMonth(), hireDate.getDate());
    return candidate;
  }
  // Manila tiers: 0 (<1 yr), 3 (1-4), 5 (5-9), 10 (10+). PTO only.
  function entitlementFor(hireDate, cycleStartDate) {
    var yos = cycleStartDate.getFullYear() - hireDate.getFullYear();
    var m1 = cycleStartDate.getMonth(), d1 = cycleStartDate.getDate();
    var m0 = hireDate.getMonth(), d0 = hireDate.getDate();
    if (m1 < m0 || (m1 === m0 && d1 < d0)) yos--;
    if (yos >= 10) return 10;
    if (yos >= 5) return 5;
    if (yos >= 1) return 3;
    return 0;
  }
  // An adjustment applies to the CURRENT or NEXT window (relative to today) when
  // effective < window end and (no expiry or expiry >= window start).
  function sumAdjustments(employee, cycleStart, cycleEndEx, whichCycle) {
    var total = 0;
    for (var i = 0; i < adjustments.length; i++) {
      var a = adjustments[i];
      if (a.employee !== employee) continue;
      if (a.applyTo !== whichCycle) continue;
      if (a.effective.getTime() >= cycleEndEx.getTime()) continue;
      if (a.expiresAfter && a.expiresAfter.getTime() < cycleStart.getTime()) continue;
      total += a.days;
    }
    return total;
  }
  function workingDaysOverlap(aStart, aEnd, bStart, bEnd) {
    var start = new Date(Math.max(aStart.getTime(), bStart.getTime()));
    var end = new Date(Math.min(aEnd.getTime(), bEnd.getTime()));
    if (start > end) return 0;
    var days = 0, cur = new Date(start);
    while (cur <= end) { var dow = cur.getDay(); if (dow !== 0 && dow !== 6) days++; cur.setDate(cur.getDate() + 1); }
    return days;
  }
  function formatMDY(d) { return Utilities.formatDate(d, tz, "MM/dd/yyyy"); }
}

// Writes per-row outputs: E = current-cycle remaining after this row (rows outside both
// windows show the running current balance), F = "After MM/DD/YYYY: N" for next-cycle usage.
// Then copies each employee's final current remaining into Employee Start Date column D.
// Same helpers and semantics as getDataAsJson; menu-driven (alerts at the end).
function recalculatePtoTwoCycles() { /* see the chat transcript for the full body; logic identical to getDataAsJson, row-by-row */ }

// Allotment tier written to Employee Start Date column D based on TODAY's years of service (3/5/10).
function calculatePTO() { /* yos from today; 3 if 1-4, 5 if 5-9, 10 if 10+; writes column D */ }

// Birthday calendar sync: for each employee, this year and next; creates missing "Name Birthday"
// all-day events, deletes duplicates on the same day, and (unlike the US script) DOES delete
// events titled "* Birthday" inside [Jan 1 this year, Jan 1 in two years) that no longer match the sheet.
function addBirthdaysToCalendar() { /* see transcript */ }

// Event sheets -> calendars. Same delete-then-recreate comparison bug as the US script
// (inclusive sheet end vs exclusive calendar end never match, so every run deletes and recreates all events).
function updateAdditionalEvents() { /* 'Any Additional Events Calendar' -> b7e4ad95...; timeZone Asia/Manila (unused) */ }
function updateCalendarFactoryClosings() { /* 'Factory Closings Calendar' -> b734044e... */ }
function updateCalendarAndPTO() { /* 'Time Requested Off' -> 65b050c4..., title "Employee - PTO" */ }

function calculateWorkingDays(startDate, endDate) {
  var workingDays = 0, currentDate = new Date(startDate);
  while (currentDate <= endDate) { var dow = currentDate.getDay(); if (dow !== 0 && dow !== 6) workingDays++; currentDate.setDate(currentDate.getDate() + 1); }
  return workingDays;
}
