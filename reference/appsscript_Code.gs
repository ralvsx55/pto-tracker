// Copyright 2023 Lightsaberpromotions Inc — Apache 2.0
// Google Apps Script bound to the "LSP Calendar Update" Google Sheet.
// This is the CURRENT PTO/Vacation system that is being replaced.

// ============================================================================
// SHEET LAYOUT REFERENCE
// ============================================================================
//
// Employee Start Date:
//   A: Employee Name
//   B: Anniversary / Hire Date
//   C: Birthday
//   D: Vacation Allotment  (calculated)
//   E: PTO Allotment       (calculated)
//   F: Combined Total      (calculated)
//
// Time Requested Off:
//   A: Employee Name
//   B: Type ("PTO" or "Vacation")
//   C: Start Date
//   D: End Date
//   E: PTO Remaining        (calculated — current cycle, chronological)
//   F: Vacation Remaining   (calculated — current cycle, chronological)
//   G: Notes                (calculated — "After MM/DD/YYYY: N PTO / N Vac"
//                            for rows that fall in the next anniversary cycle)
//
// PTO Adjustments (optional — create this sheet if you want to grant or
// deduct days outside the standard allotment schedule):
//   A: Employee Name   (must match Employee Start Date)
//   B: Effective Date  (determines which anniversary cycle it applies to)
//   C: Days            (+ for a grant, - for a deduction; fractional OK)
//   D: Type            ("PTO" or "Vacation")
//   E: Note            (for your reference; not used by the script)
//
// Other sheets synced to their own Google Calendars via syncSimpleEventSheet:
//   "Any Additional Events Calendar", "Factory Closings Calendar",
//   "End Of Month Sale Calendar"  — each [Event Name, Start Date, End Date]

// ============================================================================
// UI / ENTRY POINTS
// ============================================================================

function openReadMeDialog() {
  const htmlOutput = HtmlService.createHtmlOutputFromFile('readme')
      .setWidth(800)
      .setHeight(900);
  SpreadsheetApp.getUi().showModalDialog(htmlOutput, 'Instructions On How To Use');
}

// Web-app endpoint: recalculates and returns per-employee balances as JSON.
function doGet(e) {
  var output = ContentService.createTextOutput();
  output.setMimeType(ContentService.MimeType.JSON);
  try {
    calculateRemainingDays();
    output.setContent(getDataAsJson());
  } catch (error) {
    output.setContent(JSON.stringify({ error: error.message }));
  }
  return output;
}

function calculateAndBirthdays() {
  try {
    calculateAll();
    addBirthdaysToCalendar();
    SpreadsheetApp.getUi().alert('The Calendar has updated all the Birthdays too!');
  } catch (error) {
    Logger.log('Error in calculateAndBirthdays: ' + error);
    SpreadsheetApp.getUi().alert('Error occurred: ' + error);
  }
}

// Kept for trigger compatibility. Recalculates remaining balances from first principles.
function calculateAndFlagRemaining() {
  try {
    calculateRemainingDays();
  } catch (error) {
    Logger.log('Error in calculateAndFlagRemaining: ' + error);
  }
}

function calculateAll() {
  try {
    calculateVacationDays();
    calculatePTO();
    calculateCombinedVacationPTO();
    calculateRemainingDays();
  } catch (error) {
    Logger.log('Error in calculateAll: ' + error);
  }
}

// ============================================================================
// DATE / CYCLE HELPERS
// ============================================================================

function stripTime(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
function addYears(d, n) { return new Date(d.getFullYear() + n, d.getMonth(), d.getDate()); }
function isValidDate(d) { return (d instanceof Date) && !isNaN(d); }
function formatMDY(d, tz) { return Utilities.formatDate(d, tz, "MM/dd/yyyy"); }

// Vacation allotment tiers based on years of service at the start of the PTO year.
function vacationAllotmentFor(yearsOfService) {
  if (yearsOfService >= 10) return 15;
  if (yearsOfService >= 5) return 10;
  if (yearsOfService >= 1) return 5;
  return 0;
}

// PTO allotment based on years of service at the start of the PTO year.
function ptoAllotmentFor(yearsOfService) {
  return yearsOfService >= 1 ? 3 : 0;
}

// Years of service completed as of `asOfDate`, given the employee's anniversary (hire) date.
function yearsOfServiceAt(asOfDate, anniversaryDate) {
  var years = asOfDate.getFullYear() - anniversaryDate.getFullYear();
  if (
    asOfDate.getMonth() < anniversaryDate.getMonth() ||
    (asOfDate.getMonth() === anniversaryDate.getMonth() && asOfDate.getDate() < anniversaryDate.getDate())
  ) {
    years--;
  }
  return years;
}

// Returns the anniversary-year window that `referenceDate` falls in.
// Window = [start, end) spanning 12 months, anchored on the employee's hire date.
function anniversaryWindowFor(referenceDate, anniversaryDate) {
  var refYear = referenceDate.getFullYear();
  var anniversaryThisYear = new Date(refYear, anniversaryDate.getMonth(), anniversaryDate.getDate());
  var windowStart = (anniversaryThisYear <= referenceDate)
    ? anniversaryThisYear
    : new Date(refYear - 1, anniversaryDate.getMonth(), anniversaryDate.getDate());
  return { start: windowStart, end: addYears(windowStart, 1) };
}

// Working days (Mon-Fri) between two dates, inclusive. Holidays are NOT excluded.
function calculateWorkingDays(startDate, endDate) {
  var workingDays = 0;
  var currentDate = new Date(startDate);
  while (currentDate <= endDate) {
    var dayOfWeek = currentDate.getDay();
    if (dayOfWeek !== 0 && dayOfWeek !== 6) workingDays++;
    currentDate.setDate(currentDate.getDate() + 1);
  }
  return workingDays;
}

function buildAnniversaryMap(sheet2Values) {
  var map = {};
  for (var k = 0; k < sheet2Values.length; k++) {
    var emp = sheet2Values[k][0];
    var anniversary = sheet2Values[k][1];
    if (emp && isValidDate(anniversary)) map[emp] = stripTime(anniversary);
  }
  return map;
}

// ============================================================================
// PTO ADJUSTMENTS (optional sheet — does not currently exist in the workbook)
// ============================================================================

function loadAdjustments() {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName('PTO Adjustments');
  if (!sheet || sheet.getLastRow() < 2) return {};
  var data = sheet.getRange(2, 1, sheet.getLastRow() - 1, 5).getValues();
  var byEmployee = {};
  for (var i = 0; i < data.length; i++) {
    var name = String(data[i][0] || '').trim();
    var effective = data[i][1];
    var days = parseFloat(data[i][2]);
    var type = String(data[i][3] || '').trim().toLowerCase();
    if (!name || !isValidDate(effective) || isNaN(days) || days === 0) continue;
    if (type !== 'pto' && type !== 'vacation') continue;
    if (!byEmployee[name]) byEmployee[name] = [];
    byEmployee[name].push({ effective: stripTime(effective), days: days, type: type });
  }
  return byEmployee;
}

// Sum adjustments whose effective date falls within [cycleStart, cycleEnd).
function sumAdjustments(employeeAdjustments, cycleStart, cycleEnd, type) {
  if (!employeeAdjustments) return 0;
  var total = 0;
  for (var i = 0; i < employeeAdjustments.length; i++) {
    var adj = employeeAdjustments[i];
    if (adj.type !== type) continue;
    if (adj.effective < cycleStart) continue;
    if (adj.effective >= cycleEnd) continue;
    total += adj.days;
  }
  return total;
}

// ============================================================================
// DATA EXPORT (web app JSON endpoint)
// ============================================================================

function getDataAsJson() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var tz = ss.getSpreadsheetTimeZone();
  var sheet1 = ss.getSheetByName('Time Requested Off');
  var sheet2 = ss.getSheetByName('Employee Start Date');
  var sheet1Rows = sheet1.getLastRow() > 1 ? sheet1.getRange(2, 1, sheet1.getLastRow() - 1, 6).getValues() : [];
  var sheet2Rows = sheet2.getLastRow() > 1 ? sheet2.getRange(2, 1, sheet2.getLastRow() - 1, 6).getValues() : [];
  var anniversaryMap = buildAnniversaryMap(sheet2Rows);
  var adjustmentsByEmployee = loadAdjustments();
  var today = stripTime(new Date());
  var jsonData = [];
  for (var j = 0; j < sheet2Rows.length; j++) {
    var employee = sheet2Rows[j][0];
    var anniversary = sheet2Rows[j][1];
    if (!employee) continue;
    var current = computeCycleRemaining(employee, anniversaryMap, adjustmentsByEmployee[employee], sheet1Rows, today, 0);
    var next = computeCycleRemaining(employee, anniversaryMap, adjustmentsByEmployee[employee], sheet1Rows, today, 1);
    jsonData.push({
      "employee": employee,
      "anniversaryDate": anniversary,
      "remainingPTO": current.pto,                   // legacy key
      "remainingVacation": current.vacation,          // legacy key
      "remainingPTOCurrent": current.pto,
      "remainingVacationCurrent": current.vacation,
      "remainingPTONext": next.pto,
      "remainingVacationNext": next.vacation,
      "afterDate": next.cycleStart ? formatMDY(next.cycleStart, tz) : null
    });
  }
  return JSON.stringify(jsonData);
}

// Remaining balance for a specific anniversary cycle offset from today's current cycle.
// cycleOffset = 0 -> current cycle; 1 -> next cycle; -1 -> previous cycle; etc.
function computeCycleRemaining(employee, anniversaryMap, adjustments, sheet1Rows, today, cycleOffset) {
  var hire = anniversaryMap[employee];
  if (!hire) return { pto: 0, vacation: 0, cycleStart: null };
  var currentWindow = anniversaryWindowFor(today, hire);
  var cycleStart = cycleOffset === 0 ? currentWindow.start : addYears(currentWindow.start, cycleOffset);
  var cycleEnd = addYears(cycleStart, 1);
  var yos = yearsOfServiceAt(cycleStart, hire);
  var ptoAllot = ptoAllotmentFor(yos) + sumAdjustments(adjustments, cycleStart, cycleEnd, 'pto');
  var vacAllot = vacationAllotmentFor(yos) + sumAdjustments(adjustments, cycleStart, cycleEnd, 'vacation');
  var ptoUsed = 0, vacUsed = 0;
  for (var i = 0; i < sheet1Rows.length; i++) {
    if (sheet1Rows[i][0] !== employee) continue;
    var type = String(sheet1Rows[i][1] || '').trim().toLowerCase();
    var start = sheet1Rows[i][2];
    var end = sheet1Rows[i][3];
    if (!isValidDate(start) || !isValidDate(end)) continue;
    if (start < cycleStart || start >= cycleEnd) continue;   // request belongs to the cycle containing its START date
    var days = calculateWorkingDays(start, end);
    if (type === 'pto') ptoUsed += days;
    else if (type === 'vacation') vacUsed += days;
  }
  return { pto: ptoAllot - ptoUsed, vacation: vacAllot - vacUsed, cycleStart: cycleStart };
}

// ============================================================================
// CALENDAR SYNC: BIRTHDAYS
// ============================================================================

// Birthday calendar: 8579146e437a950aaa8b704aab72515388e5dd18eaa7d086167f02810f776bd8@group.calendar.google.com

function addBirthdaysToCalendar() {
  var spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = spreadsheet.getSheetByName('Employee Start Date');
  var data = sheet.getRange(2, 1, sheet.getLastRow() - 1, 3).getValues();
  var calendarId = '8579146e437a950aaa8b704aab72515388e5dd18eaa7d086167f02810f776bd8@group.calendar.google.com';
  var calendar = CalendarApp.getCalendarById(calendarId);
  var currentYear = new Date().getFullYear();
  var yearsToHandle = [currentYear, currentYear + 1];
  var existingEvents = calendar.getEvents(new Date(2000, 0, 1), new Date(2040, 0, 1));
  var eventTitles = {};
  for (var i = 0; i < existingEvents.length; i++) {
    eventTitles[existingEvents[i].getTitle() + existingEvents[i].getStartTime().toDateString()] = true;
  }
  for (var j = 0; j < data.length; j++) {
    var employeeName = data[j][0];
    var birthday = new Date(data[j][2]);
    if (employeeName && birthday) {
      for (var year of yearsToHandle) {
        var birthdayDate = new Date(year, birthday.getMonth(), birthday.getDate());
        var title = employeeName + ' Birthday';
        var key = title + birthdayDate.toDateString();
        if (!eventTitles[key]) {
          calendar.createAllDayEvent(title, birthdayDate, { description: 'Birthday' });
          eventTitles[key] = true;
        }
      }
    }
  }
  // Deletes any event on the birthday calendar that was not (re)generated above.
  for (var k = 0; k < existingEvents.length; k++) {
    var event = existingEvents[k];
    var key = event.getTitle() + event.getStartTime().toDateString();
    if (!eventTitles[key]) event.deleteEvent();
  }
}

// ============================================================================
// CALENDAR SYNC: GENERIC EVENT SHEETS   [Event Name, Start, End] -> all-day events
// ============================================================================

// Sync a sheet of [name, start, end] events to a calendar. Deletes calendar
// events with no matching sheet row, then creates/updates the rest.
function syncSimpleEventSheet(sheetName, calendarId) {
  var spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = spreadsheet.getSheetByName(sheetName);
  if (sheet.getLastRow() < 2 || sheet.getLastColumn() < 3) return;
  var data = sheet.getRange(2, 1, sheet.getLastRow() - 1, 3).getValues();
  var calendar = CalendarApp.getCalendarById(calendarId);
  var calendarEvents = calendar.getEvents(new Date(2021, 0, 1), new Date(2040, 0, 1));

  for (var i = 0; i < calendarEvents.length; i++) {
    var ev = calendarEvents[i];
    var existsInSheet = false;
    for (var j = 0; j < data.length; j++) {
      var sStart = new Date(data[j][1]);
      var sEnd = new Date(data[j][2]);
      if (data[j][0] === ev.getTitle() &&
          sStart.getTime() === ev.getStartTime().getTime() &&
          sEnd.getTime() === ev.getEndTime().getTime()) { existsInSheet = true; break; }
    }
    if (!existsInSheet) ev.deleteEvent();
  }

  for (var k = 0; k < data.length; k++) {
    var eventName = data[k][0];
    var startDate = new Date(data[k][1]);
    var endDate = new Date(data[k][2]);
    if (!eventName || !isValidDate(startDate) || !isValidDate(endDate)) continue;
    endDate.setDate(endDate.getDate() + 1);   // all-day end is exclusive
    var existing = calendar.getEvents(startDate, endDate, { search: eventName });
    if (existing.length > 0) {
      var ex = existing[0];
      if (ex.getTitle() !== eventName ||
          ex.getStartTime().getTime() !== startDate.getTime() ||
          ex.getEndTime().getTime() !== endDate.getTime()) {
        ex.setTitle(eventName);
        ex.setTime(startDate, endDate);
      }
    } else {
      calendar.createAllDayEvent(eventName, startDate, endDate);
    }
  }
}

function updateAdditionalEvents() {
  syncSimpleEventSheet('Any Additional Events Calendar',
    '4df8b9e4839d04a9f35bc520a86e85cc3b8856d8df185f2a0aef8cf69007f63c@group.calendar.google.com');
}
function updateCalendarFactoryClosings() {
  syncSimpleEventSheet('Factory Closings Calendar',
    '470bb84a2fadbdb4c543cc0676dc1e0e5bd413cf7ff41b39eb80b0333909db56@group.calendar.google.com');
}
function updateCalendarOtherEvents() {
  syncSimpleEventSheet('End Of Month Sale Calendar',
    'e96813af5476467dd32b9eab64982306f0409967ec4610be387aabdcdc899a70@group.calendar.google.com');
}

// ============================================================================
// CALENDAR SYNC: PTO / VACATION REQUESTS   (title = "Employee - Type", all-day)
// ============================================================================

function updateCalendarAndPTO() {
  var spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  var sheet1 = spreadsheet.getSheetByName('Time Requested Off');
  if (sheet1.getLastRow() < 2 || sheet1.getLastColumn() < 4) return;
  var data = sheet1.getRange(2, 1, sheet1.getLastRow() - 1, 5).getValues();
  var calendarId = '0ec358900fdb35e7d48a00c7930be8eff9a8599d9cc6f1a47a13de9b044f34a9@group.calendar.google.com';
  var calendar = CalendarApp.getCalendarById(calendarId);
  var calendarEvents = calendar.getEvents(new Date(2021, 0, 1), new Date(2040, 0, 1));

  for (var i = 0; i < calendarEvents.length; i++) {
    var ev = calendarEvents[i];
    var existsInSheet = false;
    for (var j = 0; j < data.length; j++) {
      var sStart = new Date(data[j][2]);
      var sEnd = new Date(data[j][3]);
      if (data[j][0] + " - " + data[j][1] === ev.getTitle() &&
          sStart.getTime() === ev.getStartTime().getTime() &&
          sEnd.getTime() === ev.getEndTime().getTime()) { existsInSheet = true; break; }
    }
    if (!existsInSheet) ev.deleteEvent();
  }

  for (var k = 0; k < data.length; k++) {
    var employee = data[k][0];
    var eventName = data[k][1];
    var startDate = new Date(data[k][2]);
    var endDate = new Date(data[k][3]);
    if (!employee || !eventName || isNaN(startDate) || isNaN(endDate)) continue;
    var timeZone = 'America/New_York';
    startDate = new Date(Date.parse(startDate.toLocaleString('en-US', { timeZone: timeZone })));
    endDate = new Date(Date.parse(endDate.toLocaleString('en-US', { timeZone: timeZone })));
    endDate.setDate(endDate.getDate() + 1);
    var eventTitle = employee + " - " + eventName;
    var existing = calendar.getEvents(startDate, endDate, { search: eventTitle });
    if (existing.length > 0) {
      var ex = existing[0];
      if (ex.getTitle() !== eventTitle ||
          ex.getStartTime().getTime() !== startDate.getTime() ||
          ex.getEndTime().getTime() !== endDate.getTime()) {
        ex.setTitle(eventTitle);
        ex.setTime(startDate, endDate);
      }
    } else {
      calendar.createAllDayEvent(eventTitle, startDate, endDate);
    }
  }
}

// ============================================================================
// ALLOTMENT CALCULATIONS (write D/E/F on Employee Start Date, based on TODAY)
// ============================================================================

function calculateVacationDays() {
  var sheet2 = SpreadsheetApp.getActiveSpreadsheet().getSheetByName('Employee Start Date');
  var data = sheet2.getRange(2, 2, sheet2.getLastRow() - 1, 1).getValues();
  var today = new Date();
  for (var i = 0; i < data.length; i++) {
    var anniversaryDate = new Date(data[i][0]);
    if (isNaN(anniversaryDate)) continue;
    sheet2.getRange(i + 2, 4).setValue(vacationAllotmentFor(yearsOfServiceAt(today, anniversaryDate)));
  }
}

function calculatePTO() {
  var sheet2 = SpreadsheetApp.getActiveSpreadsheet().getSheetByName('Employee Start Date');
  var data = sheet2.getRange(2, 2, sheet2.getLastRow() - 1, 1).getValues();
  var today = new Date();
  for (var i = 0; i < data.length; i++) {
    var anniversaryDate = new Date(data[i][0]);
    if (isNaN(anniversaryDate)) continue;
    sheet2.getRange(i + 2, 5).setValue(ptoAllotmentFor(yearsOfServiceAt(today, anniversaryDate)));
  }
}

function calculateCombinedVacationPTO() {
  var sheet2 = SpreadsheetApp.getActiveSpreadsheet().getSheetByName('Employee Start Date');
  var data = sheet2.getRange(2, 1, sheet2.getLastRow() - 1, 6).getValues();
  for (var i = 0; i < data.length; i++) {
    sheet2.getRange(i + 2, 6).setValue((parseFloat(data[i][3]) || 0) + (parseFloat(data[i][4]) || 0));
  }
}

// ============================================================================
// REMAINING DAYS (anniversary-based, date-sorted, with adjustments)
// ============================================================================
//
// For each employee:
//   1. Sort their requests by start date.
//   2. Each request belongs to the anniversary-year window containing its START date.
//   3. When the window changes, reset running totals and pull the allotment for
//      that window (years-of-service at window start, plus PTO Adjustments whose
//      effective date falls inside the window).
//   4. Subtract this request's working days (Mon-Fri, inclusive) from the cycle's
//      running total; write remaining PTO to E and remaining Vacation to F.
//   5. If the request falls in the NEXT anniversary cycle relative to today,
//      annotate G with "After MM/DD/YYYY: N PTO / N Vac".
// Balances are computed from first principles; there is no stored state.

function calculateRemainingDays() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var tz = ss.getSpreadsheetTimeZone();
  var sheet1 = ss.getSheetByName('Time Requested Off');
  var sheet2 = ss.getSheetByName('Employee Start Date');
  if (sheet1.getLastRow() < 2) return;

  var rowCount = sheet1.getLastRow() - 1;
  var dataSheet1 = sheet1.getRange(2, 1, rowCount, 6).getValues();
  var dataSheet2 = sheet2.getRange(2, 1, sheet2.getLastRow() - 1, 6).getValues();
  var anniversaryMap = buildAnniversaryMap(dataSheet2);
  var adjustmentsByEmployee = loadAdjustments();
  var today = stripTime(new Date());

  var outE = [], outF = [], outG = [];
  for (var i = 0; i < rowCount; i++) { outE.push(['']); outF.push(['']); outG.push(['']); }

  var byEmployee = {};
  for (var i = 0; i < dataSheet1.length; i++) {
    var emp = dataSheet1[i][0];
    if (!emp) continue;
    if (!byEmployee[emp]) byEmployee[emp] = [];
    byEmployee[emp].push({
      idx: i,
      type: String(dataSheet1[i][1] || '').trim().toLowerCase(),
      start: dataSheet1[i][2],
      end: dataSheet1[i][3]
    });
  }

  Object.keys(byEmployee).forEach(function(emp) {
    var hire = anniversaryMap[emp];
    if (!hire) return;
    var adjustments = adjustmentsByEmployee[emp];
    var todayCycleStart = anniversaryWindowFor(today, hire).start;
    var nextCycleStart = addYears(todayCycleStart, 1);

    var items = byEmployee[emp].slice();
    items.sort(function(a, b) {
      var aValid = isValidDate(a.start), bValid = isValidDate(b.start);
      if (aValid && bValid) { var diff = a.start.getTime() - b.start.getTime(); if (diff !== 0) return diff; }
      else if (aValid) return -1;
      else if (bValid) return 1;
      return a.idx - b.idx;
    });

    var cycleKey = null, allotPto = 0, allotVac = 0, usedPto = 0, usedVac = 0, cycleStart = null;
    for (var k = 0; k < items.length; k++) {
      var it = items[k];
      if (!isValidDate(it.start) || !isValidDate(it.end)) continue;
      if (it.type !== 'pto' && it.type !== 'vacation') continue;

      var win = anniversaryWindowFor(it.start, hire);
      var thisCycleKey = win.start.getTime();
      if (thisCycleKey !== cycleKey) {
        cycleKey = thisCycleKey;
        cycleStart = win.start;
        var yos = yearsOfServiceAt(cycleStart, hire);
        allotPto = ptoAllotmentFor(yos) + sumAdjustments(adjustments, win.start, win.end, 'pto');
        allotVac = vacationAllotmentFor(yos) + sumAdjustments(adjustments, win.start, win.end, 'vacation');
        usedPto = 0; usedVac = 0;
      }

      var days = calculateWorkingDays(it.start, it.end);
      if (it.type === 'pto') usedPto += days; else usedVac += days;

      var remPto = allotPto - usedPto, remVac = allotVac - usedVac;
      outE[it.idx] = [remPto];
      outF[it.idx] = [remVac];
      if (cycleStart.getTime() === nextCycleStart.getTime()) {
        outG[it.idx] = ['After ' + formatMDY(cycleStart, tz) + ': ' + remPto + ' PTO / ' + remVac + ' Vac'];
      }
    }
  });

  sheet1.getRange(2, 5, rowCount, 1).setValues(outE);
  sheet1.getRange(2, 6, rowCount, 1).setValues(outF);
  sheet1.getRange(2, 7, rowCount, 1).setValues(outG);
}
