/* PTO Tracker v1.0.1 - deploy check */
/* PTO Tracker theme switch. Loaded in <head> BEFORE the stylesheet (layout_header) so the first paint already
   carries data-theme and there is no light/dark flash. Dark is the default; the choice lives in localStorage
   under "pto-theme" (per browser). Any element with data-theme-toggle flips it. Nothing is exposed globally.
   No inline scripts anywhere (CSP). */
(function () {
  'use strict';

  var KEY = 'pto-theme';

  function read() {
    try {
      var v = window.localStorage.getItem(KEY);
      return v === 'light' || v === 'dark' ? v : 'dark';
    } catch (e) {
      return 'dark';   // storage blocked (private mode, disabled cookies): default, not remembered
    }
  }

  function store(v) {
    try {
      window.localStorage.setItem(KEY, v);
    } catch (e) {
      // not remembered; the page still switches
    }
  }

  function apply(v) {
    document.documentElement.setAttribute('data-theme', v);
  }

  apply(read());

  document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (ev) {
      var target = ev.target && ev.target.closest ? ev.target.closest('[data-theme-toggle]') : null;
      if (!target) {
        return;
      }
      var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      apply(next);
      store(next);
    });
  });
})();
