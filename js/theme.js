/* js/theme.js — manual dark/light/auto theme override.
 *
 * Persists preference in localStorage as 'light' | 'dark' | (absent = auto).
 * A tiny inline script in <head> reads this before CSS loads to prevent
 * a flash of the wrong theme (FOCT).
 */
(function () {
  var ICONS  = { light: '☀', dark: '🌙', auto: '⊙' };
  var LABELS = { light: 'Switch to dark theme', dark: 'Switch to auto theme', auto: 'Switch to light theme' };

  function getTheme() {
    return localStorage.getItem('theme'); // 'light' | 'dark' | null
  }

  function applyTheme(t) {
    if (t) {
      document.documentElement.setAttribute('data-theme', t);
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
  }

  function cycleTheme() {
    var cur  = getTheme();
    var next = cur === null ? 'light' : cur === 'light' ? 'dark' : null;
    if (next) {
      localStorage.setItem('theme', next);
    } else {
      localStorage.removeItem('theme');
    }
    applyTheme(next);
    updateBtn();
  }

  function updateBtn() {
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    var t = getTheme();
    var key = t === null ? 'auto' : t;
    btn.textContent = ICONS[key];
    btn.setAttribute('aria-label', LABELS[key]);
  }

  // Attach click handler once DOM is ready.
  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('theme-toggle');
    if (btn) btn.addEventListener('click', cycleTheme);
    updateBtn();
  });
}());
