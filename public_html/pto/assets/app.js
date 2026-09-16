/* PTO Tracker: tiny helpers. No inline scripts anywhere (CSP). */
(function () {
  'use strict';

  // Auto-focus: the first element marked data-autofocus, else the first text-like input of the first form.
  function autoFocus() {
    var el = document.querySelector('[data-autofocus]');
    if (!el) {
      var form = document.querySelector('form[data-autofocus-first]');
      if (form) {
        el = form.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea');
      }
    }
    if (el && typeof el.focus === 'function') {
      el.focus();
    }
  }

  // Confirm-on-submit for <form data-confirm="Are you sure?">.
  function confirmForms() {
    document.addEventListener('submit', function (ev) {
      var form = ev.target;
      if (form && form.hasAttribute && form.hasAttribute('data-confirm')) {
        if (!window.confirm(form.getAttribute('data-confirm') || 'Are you sure?')) {
          ev.preventDefault();
        }
      }
    });
  }

  // End-date auto-fill: <input id="end" data-follow="start">. When start changes and end is empty or before it,
  // end = start.
  function followDates() {
    var followers = document.querySelectorAll('input[data-follow]');
    Array.prototype.forEach.call(followers, function (end) {
      var start = document.getElementById(end.getAttribute('data-follow'));
      if (!start) {
        return;
      }
      start.addEventListener('change', function () {
        if (!end.value || end.value < start.value) {
          end.value = start.value;
          end.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    });
  }

  // ---- Sortable tables --------------------------------------------------------------------------------------
  // <table class="sortable"> with <th data-sort="text|num|date|month"> headers. Clicking a header sorts the
  // tbody rows by that column (stable), ascending first, descending on the second click, and marks the header
  // with .sorted-asc / .sorted-desc. A cell can carry its sort value in data-v (used whenever present):
  //   num   - parsed as a float; blanks (or anything non-numeric) sort last in both directions
  //   date  - data-v "Y-m-d", else the cell text as MM/DD/YYYY (converted to Y-m-d); blanks last
  //   month - data-v "MM-DD" (birthdays: month then day); blanks last
  //   text  - case-insensitive, natural ("2" before "10")
  // Rows left alone: a row with a single cell spanning the table (the "No ..." placeholder), which stays at the
  // bottom, and a row with data-nosort (the inline add row on Events), which stays at the top.
  // Headers without data-sort are not clickable.

  function sortKey(cell, type) {
    var v = cell.hasAttribute('data-v') ? cell.getAttribute('data-v') : cell.textContent;
    v = (v || '').trim();
    if (v === '') {
      return null;
    }
    if (type === 'num') {
      var n = parseFloat(v);
      return isNaN(n) ? null : n;
    }
    if (type === 'date') {
      if (!cell.hasAttribute('data-v')) {
        var m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})/.exec(v);
        if (!m) {
          return null;
        }
        v = m[3] + '-' + ('0' + m[1]).slice(-2) + '-' + ('0' + m[2]).slice(-2);
      }
      return v;
    }
    if (type === 'month') {
      return v;   // "MM-DD" compares as a string
    }
    return v.toLowerCase();
  }

  function compareKeys(a, b, type) {
    if (type === 'num') {
      return a - b;
    }
    if (type === 'text') {
      return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    }
    return a < b ? -1 : (a > b ? 1 : 0);
  }

  function isPlaceholderRow(tr, columns) {
    return tr.cells.length === 1 && columns > 1 && (parseInt(tr.cells[0].getAttribute('colspan') || '1', 10) > 1);
  }

  function sortTable(table, th) {
    var tbody = table.tBodies[0];
    if (!tbody) {
      return;
    }
    var headers = table.tHead ? table.tHead.rows[table.tHead.rows.length - 1].cells : [];
    var columns = headers.length;
    var index = Array.prototype.indexOf.call(headers, th);
    if (index < 0) {
      return;
    }
    var type = th.getAttribute('data-sort') || 'text';
    var dir = th.classList.contains('sorted-asc') ? -1 : 1;

    var pinnedTop = [];
    var pinnedBottom = [];
    var sortable = [];
    Array.prototype.forEach.call(tbody.rows, function (tr, i) {
      if (tr.hasAttribute('data-nosort')) {
        pinnedTop.push(tr);
      } else if (isPlaceholderRow(tr, columns)) {
        pinnedBottom.push(tr);
      } else {
        var cell = tr.cells[index];
        sortable.push({ tr: tr, i: i, key: cell ? sortKey(cell, type) : null });
      }
    });

    // Stable: equal keys (and blanks) keep their original order; blanks always go last.
    sortable.sort(function (a, b) {
      if (a.key === null && b.key === null) {
        return a.i - b.i;
      }
      if (a.key === null) {
        return 1;
      }
      if (b.key === null) {
        return -1;
      }
      var c = compareKeys(a.key, b.key, type) * dir;
      return c !== 0 ? c : a.i - b.i;
    });

    pinnedTop.forEach(function (tr) { tbody.appendChild(tr); });
    sortable.forEach(function (r) { tbody.appendChild(r.tr); });
    pinnedBottom.forEach(function (tr) { tbody.appendChild(tr); });

    Array.prototype.forEach.call(headers, function (other) {
      other.classList.remove('sorted-asc', 'sorted-desc');
      other.removeAttribute('aria-sort');
    });
    th.classList.add(dir === 1 ? 'sorted-asc' : 'sorted-desc');
    th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
  }

  function sortableTables() {
    var tables = document.querySelectorAll('table.sortable');
    Array.prototype.forEach.call(tables, function (table) {
      var ths = table.querySelectorAll('thead th[data-sort]');
      Array.prototype.forEach.call(ths, function (th) {
        th.setAttribute('tabindex', '0');
        th.addEventListener('click', function () {
          sortTable(table, th);
        });
        th.addEventListener('keydown', function (ev) {
          if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            sortTable(table, th);
          }
        });
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    autoFocus();
    confirmForms();
    followDates();
    sortableTables();
  });
})();
