/* ==========================================================================
   MaizeGDB Modern — global chrome enhancement
   --------------------------------------------------------------------------
   Progressive enhancement for the existing site header, megamenu, and footer.
   The shipped markup and all six megamenu content templates are left untouched;
   this only adds the state and keyboard behavior they are missing.

   1. A mobile navigation toggle, shown by CSS only below 900px.
   2. aria-haspopup / aria-expanded on the megamenu triggers, kept in sync with
      real hover and focus state.
   3. Escape closes an open panel and returns focus to its trigger.

   Without this file the navigation still works: CSS :focus-within already
   reveals the panels for keyboard users, and the drawer falls back to a
   normally visible menu.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MOBILE_QUERY = '(max-width: 900px)';

  function setupNavToggle(menu) {
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'mgdb-nav-toggle';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-controls', menu.id || 'menu_bar');
    toggle.appendChild(document.createTextNode('Menu'));

    menu.parentNode.insertBefore(toggle, menu);

    var media = window.matchMedia ? window.matchMedia(MOBILE_QUERY) : null;

    function applyState(expanded) {
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      menu.setAttribute('data-mgdb-nav', expanded ? 'expanded' : 'collapsed');
    }

    function syncToViewport() {
      if (media && media.matches) {
        // Collapsed by default on small screens.
        applyState(toggle.getAttribute('aria-expanded') === 'true');
      } else {
        // Above the breakpoint the attribute is irrelevant; clear it so the
        // shipped desktop styles apply untouched.
        menu.removeAttribute('data-mgdb-nav');
        toggle.setAttribute('aria-expanded', 'false');
      }
    }

    toggle.addEventListener('click', function () {
      applyState(toggle.getAttribute('aria-expanded') !== 'true');
    });

    if (media) {
      if (media.addEventListener) { media.addEventListener('change', syncToViewport); }
      else if (media.addListener) { media.addListener(syncToViewport); }
    }

    syncToViewport();
  }

  function setupMenuTriggers(menu) {
    var items = Array.prototype.slice.call(menu.querySelectorAll('li'));

    items.forEach(function (item) {
      var panel = item.querySelector('[class*="dropdown_"]');
      var trigger = item.querySelector('a');
      if (!panel || !trigger) { return; }

      trigger.setAttribute('aria-haspopup', 'true');
      trigger.setAttribute('aria-expanded', 'false');

      function open() {
        item.classList.remove('mgdb-menu-closed');
        trigger.setAttribute('aria-expanded', 'true');
      }

      function close(returnFocus) {
        // The class keeps the panel shut while focus is still inside it,
        // which :focus-within alone would keep open.
        item.classList.add('mgdb-menu-closed');
        trigger.setAttribute('aria-expanded', 'false');
        if (returnFocus) { trigger.focus(); }
      }

      item.addEventListener('focusin', open);
      item.addEventListener('mouseenter', open);

      item.addEventListener('mouseleave', function () {
        if (!item.contains(document.activeElement)) { close(false); }
      });

      item.addEventListener('focusout', function () {
        // focusout fires before the new element receives focus.
        window.setTimeout(function () {
          if (!item.contains(document.activeElement)) { close(false); }
        }, 0);
      });

      item.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' || event.key === 'Esc') {
          event.stopPropagation();
          close(true);
        }
      });

      // The trigger is href="#" and does nothing on activation; opening the
      // panel is the only meaningful action, so make that explicit.
      trigger.addEventListener('click', function (event) {
        if (trigger.getAttribute('href') === '#') {
          event.preventDefault();
          if (item.classList.contains('mgdb-menu-closed')) { open(); }
          else { close(false); }
        }
      });

      // Re-opening after Escape requires clearing the closed state on re-entry.
      item.addEventListener('mouseenter', function () { item.classList.remove('mgdb-menu-closed'); });
    });
  }

  function init() {
    var menu = document.getElementById('menu_bar');
    if (!menu) { return; }

    setupNavToggle(menu);
    setupMenuTriggers(menu);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
