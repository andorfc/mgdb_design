/* Homepage: switch the resource index between quick links and the list.

   Quick links are the default so the page opens the way the production site
   does. The choice is remembered per browser, because someone who prefers the
   dense list should not have to pick it on every visit.

   Both panels are in the HTML from the start, so the page works with no
   JavaScript at all -- it simply shows the quick links, which is the view the
   markup ships expanded. */

(function () {
  'use strict';

  var STORAGE_KEY = 'mgdb-home-view';
  var VALID = ['grid', 'list', 'tasks'];

  function read() {
    try {
      var stored = window.localStorage.getItem(STORAGE_KEY);
      return VALID.indexOf(stored) === -1 ? null : stored;
    } catch (error) {
      // Private browsing and blocked storage both throw here; the default view
      // is still correct, so there is nothing to recover from.
      return null;
    }
  }

  function write(view) {
    try {
      window.localStorage.setItem(STORAGE_KEY, view);
    } catch (error) { /* not worth surfacing */ }
  }

  function initialize() {
    var buttons = document.querySelectorAll('[data-home-view]');
    var panels = document.querySelectorAll('[data-home-panel]');
    if (!buttons.length || !panels.length) return;

    var heading = document.getElementById('home-index-title');

    function show(view, remember) {
      Array.prototype.forEach.call(panels, function (panel) {
        panel.hidden = panel.getAttribute('data-home-panel') !== view;
      });
      /* The heading names the view, so it has to follow it */
      if (heading) {
        if (view === 'list') {
          heading.textContent = 'Key resources';
        } else if (view === 'tasks') {
          heading.textContent = 'Common tasks';
        } else {
          heading.textContent = 'Quick links';
        }
      }
      Array.prototype.forEach.call(buttons, function (button) {
        var active = button.getAttribute('data-home-view') === view;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      if (remember) write(view);
    }

    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('click', function () {
        show(button.getAttribute('data-home-view'), true);
      });
    });

    var stored = read();
    if (stored) show(stored, false);
  }

  /* Bauplan::includeScript() emits into <head>, so this runs while the document
     is still parsing and every query above would come back empty. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
})();
