/* Time-off form (request.php): live preview from preview.php on every change. No inline scripts (CSP). */
(function () {
  'use strict';

  var form = document.getElementById('request-form');
  if (!form) {
    return;
  }
  var url = form.getAttribute('data-preview-url') || 'preview.php';
  var exclude = form.getAttribute('data-exclude') || '';
  var box = document.getElementById('preview');
  var splitBtn = document.getElementById('split-btn');
  var emptyText = box ? (box.getAttribute('data-empty') || '') : '';
  var timer = null;
  var seq = 0;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) {
      e.className = cls;
    }
    if (text !== undefined) {
      e.textContent = text;
    }
    return e;
  }

  function fieldValue(name) {
    var f = form.elements[name];
    return f && typeof f.value === 'string' ? f.value : '';
  }

  function kindValue() {
    var checked = form.querySelector('input[name="kind"]:checked');
    return checked ? checked.value : '';
  }

  // 'YYYY-MM-DD' -> 'MM/DD/YYYY' without Date() so no timezone shift can creep in.
  function mdy(ymd) {
    var p = String(ymd || '').split('-');
    return p.length === 3 ? p[1] + '/' + p[2] + '/' + p[0] : String(ymd || '');
  }

  function showSplit(split) {
    if (!splitBtn) {
      return;
    }
    if (!split || split.length !== 2) {
      splitBtn.hidden = true;
      return;
    }
    // US start_cycle policy: the engine proposes [[s1, e1], [s2, e2]] around the anniversary.
    splitBtn.textContent = 'Split into two rows (' + mdy(split[0][0]) + ' - ' + mdy(split[0][1]) + ' and ' + mdy(split[1][0]) + ' - ' + mdy(split[1][1]) + ')';
    splitBtn.hidden = false;
  }

  function render(data) {
    box.textContent = '';
    if (!data || data.error) {
      box.appendChild(el('div', 'err', (data && data.error) ? data.error : 'Preview unavailable.'));
      showSplit(null);
      return;
    }
    // "3 working days. PTO 2 of 3 left in cycle ..."
    box.appendChild(el('div', 'line', data.summary_line || ''));

    var remaining = data.remaining_after || {};
    var parts = [];
    Object.keys(remaining).forEach(function (kind) {
      parts.push(kind + ' ' + remaining[kind]);
    });
    if (parts.length) {
      box.appendChild(el('div', 'muted', 'Remaining after this request: ' + parts.join(', ')));
    }

    var warnings = data.warnings || [];
    if (warnings.length) {
      var ul = el('ul', 'warnings');
      warnings.forEach(function (w) {
        ul.appendChild(el('li', 'warn', w));
      });
      box.appendChild(ul);
    }
    showSplit(data.straddle && data.straddle.split ? data.straddle.split : null);
  }

  function update() {
    var employee = fieldValue('employee_id');
    var kind = kindValue();
    var start = fieldValue('start');
    var end = fieldValue('end');
    if (!employee || !kind || !start || !end) {
      box.textContent = emptyText;
      showSplit(null);
      return;
    }
    var mine = ++seq;
    var query = url + '?employee_id=' + encodeURIComponent(employee)
      + '&kind=' + encodeURIComponent(kind)
      + '&start=' + encodeURIComponent(start)
      + '&end=' + encodeURIComponent(end)
      + (exclude ? '&exclude=' + encodeURIComponent(exclude) : '');
    fetch(query, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (mine === seq) {   // ignore a slow reply that an even newer change has superseded
          render(data);
        }
      })
      .catch(function () {
        if (mine === seq) {
          render({ error: 'Preview unavailable (are you still logged in?).' });
        }
      });
  }

  function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(update, 150);
  }

  form.addEventListener('change', schedule);
  form.addEventListener('input', function (ev) {
    var t = ev.target;
    if (t && (t.type === 'date' || t.tagName === 'SELECT')) {
      schedule();
    }
  });

  // Edit mode (or a re-rendered post): fetch straight away so the box reflects the current fields.
  if (fieldValue('start') && fieldValue('end')) {
    update();
  }
})();
