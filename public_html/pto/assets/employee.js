/* employees.php + employee.php helpers. Everything is data-attribute driven; no inline scripts (CSP).
 *
 *  <form data-name-form data-existing-names='["Walker, Rebecca", ...]' data-original-name="Walker, Rebecca">
 *     <input data-name-part="last"> <input data-name-part="first">   (US: composes "Last, First")
 *     <input data-name-single>                                       (Manila: one Name field)
 *     <output data-name-preview>                                     live composed name
 *     <div data-dup-warning hidden>                                  shown when the name already exists
 *     <div data-name-change-warning hidden>                          shown when the name differs from the original
 *  <form data-adjust-form data-hire-date="2012-03-08">
 *     <input type="date" data-adjust-date>  <span data-cycle-preview>   "applies to the cycle 03/08/2026 - 03/07/2027"
 */
(function () {
  'use strict';

  function pad2(n) { return (n < 10 ? '0' : '') + n; }
  function fmt(d) { return pad2(d.getMonth() + 1) + '/' + pad2(d.getDate()) + '/' + d.getFullYear(); }
  function parseYmd(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  }

  /* SPEC section 4 rule 1 (preview only; the server recomputes with the PHP engine):
     A = (ref.year, hire.month, hire.day); if A <= ref then A else the year before. new Date() rolls Feb 29 like setDate(). */
  function cycleStart(hire, ref) {
    var a = new Date(ref.getFullYear(), hire.getMonth(), hire.getDate());
    return a <= ref ? a : new Date(ref.getFullYear() - 1, hire.getMonth(), hire.getDate());
  }
  function cycleEndInclusive(start) {
    var end = new Date(start.getFullYear() + 1, start.getMonth(), start.getDate());   // exclusive end
    end.setDate(end.getDate() - 1);
    return end;
  }

  // Composes "Last, First" (or reads the single field), shows the preview and the two warnings.
  function nameForm(form) {
    var last = form.querySelector('[data-name-part="last"]');
    var first = form.querySelector('[data-name-part="first"]');
    var single = form.querySelector('[data-name-single]');
    var preview = form.querySelector('[data-name-preview]');
    var dupWarn = form.querySelector('[data-dup-warning]');
    var changeWarn = form.querySelector('[data-name-change-warning]');
    var existing = [];
    try { existing = JSON.parse(form.getAttribute('data-existing-names') || '[]'); } catch (e) { existing = []; }
    if (!Array.isArray(existing)) { existing = existing && typeof existing === 'object' ? Object.keys(existing).map(function (k) { return existing[k]; }) : []; }
    existing = existing.map(function (n) { return String(n).trim().toLowerCase(); });
    var original = (form.getAttribute('data-original-name') || '').trim();

    function composed() {
      if (single) { return single.value.trim(); }
      var l = last ? last.value.trim() : '';
      var f = first ? first.value.trim() : '';
      return f ? l + ', ' + f : l;
    }
    function update() {
      var name = composed();
      if (preview) { preview.value = name || '—'; }
      if (dupWarn) {
        var isDup = name !== '' && name.toLowerCase() !== original.toLowerCase() && existing.indexOf(name.toLowerCase()) !== -1;
        dupWarn.hidden = !isDup;
      }
      if (changeWarn) {
        changeWarn.hidden = !(original !== '' && name !== '' && name !== original);
      }
    }
    [last, first, single].forEach(function (el) {
      if (el) { el.addEventListener('input', update); }
    });
    update();
  }

  // "applies to the cycle MM/DD/YYYY - MM/DD/YYYY" under the effective-date field.
  function adjustForm(form) {
    var hire = parseYmd(form.getAttribute('data-hire-date'));
    var input = form.querySelector('[data-adjust-date]');
    var out = form.querySelector('[data-cycle-preview]');
    if (!hire || !input || !out) { return; }
    function update() {
      var ref = parseYmd(input.value);
      if (!ref) { out.textContent = ''; return; }
      var start = cycleStart(hire, ref);
      out.innerHTML = '';
      out.appendChild(document.createTextNode('Applies to the cycle '));
      var b = document.createElement('b');
      b.textContent = fmt(start) + ' - ' + fmt(cycleEndInclusive(start));
      out.appendChild(b);
      out.appendChild(document.createTextNode('.'));
    }
    input.addEventListener('input', update);
    input.addEventListener('change', update);
    update();
  }

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('form[data-name-form]'), nameForm);
    Array.prototype.forEach.call(document.querySelectorAll('form[data-adjust-form]'), adjustForm);
  });
})();
