/* ==========================================================================
   MaizeGDB Modern — global chrome enhancement
   --------------------------------------------------------------------------
   Progressive enhancement for the existing site header, megamenu, and footer.
   The shipped markup and all six megamenu content templates are left untouched;
   this only adds the state and keyboard behavior they are missing.

   1. A mobile navigation toggle, shown by CSS only below the drawer
      breakpoint.
   2. aria-haspopup / aria-expanded on the megamenu triggers, kept in sync with
      real hover and focus state.
   3. Escape closes an open panel and returns focus to its trigger.
   4. In the drawer, a first tap on a panel trigger opens the panel instead of
      following its href.
   5. A panel that carries a [data-mgdb-hint] region shows the description of
      whichever of its links is hovered or focused.

   Without this file the navigation still works: CSS :focus-within already
   reveals the panels for keyboard users, and the drawer falls back to a
   normally visible menu.
   ========================================================================== */

(function (window, document) {
  'use strict';

  /* Must stay in step with the drawer breakpoint in css/mgdb-megamenu.css. If
     they drift, the viewport band between them renders the stacked drawer with
     no button to open it, or the desktop bar with a stray Menu button. */
  var MOBILE_QUERY = '(max-width: 1164px)';

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
    var drawerMedia = window.matchMedia ? window.matchMedia(MOBILE_QUERY) : null;

    function inDrawer() { return !!(drawerMedia && drawerMedia.matches); }

    items.forEach(function (item) {
      var panel = item.querySelector('[class*="dropdown_"]');
      var trigger = item.querySelector('a');
      if (!panel || !trigger) { return; }

      trigger.setAttribute('aria-haspopup', 'true');
      trigger.setAttribute('aria-expanded', 'false');

      function open() {
        item.classList.remove('mgdb-menu-closed');
        // In the drawer, hover and focus still reveal the panel through CSS,
        // but only an explicit tap latches it open. Setting the latch here
        // would make the tap's own focusin beat the click handler to it, and
        // the trigger would open and immediately close on one tap.
        if (!inDrawer()) { item.classList.add('mgdb-nav-open'); }
        trigger.setAttribute('aria-expanded', 'true');
      }

      function close(returnFocus) {
        // The class keeps the panel shut while focus is still inside it,
        // which :focus-within alone would keep open.
        item.classList.add('mgdb-menu-closed');
        item.classList.remove('mgdb-nav-open');
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

      // Four of the six triggers are href="#" and do nothing on activation, so
      // opening the panel is the only meaningful action.
      //
      // The other two -- Genomes and Data Hubs -- carry a real href. On a
      // desktop pointer that is fine: hover opens the panel and the label
      // doubles as a link to the section landing page. On a touch device there
      // is no hover, so tapping either one simply navigated to /genome or
      // /data_center/ and their panels were unreachable. Inside the drawer the
      // label is therefore the panel's disclosure control, and the landing page
      // stays reachable from the panel's own heading action.
      trigger.addEventListener('click', function (event) {
        var isDisclosure = trigger.getAttribute('href') === '#' || inDrawer();
        if (!isDisclosure) { return; }

        event.preventDefault();
        if (item.classList.contains('mgdb-nav-open')) {
          close(false);
        } else {
          item.classList.remove('mgdb-menu-closed');
          item.classList.add('mgdb-nav-open');
          trigger.setAttribute('aria-expanded', 'true');
        }
      });

      // Re-opening after Escape requires clearing the closed state on re-entry.
      item.addEventListener('mouseenter', function () { item.classList.remove('mgdb-menu-closed'); });
    });
  }

  /* Description hint
     ------------------------------------------------------------------------
     The Data Hubs panel lists twenty destinations with no room for a
     description under each. Rather than twenty native tooltips -- which do not
     appear on touch at all, wait about a second, and cannot be styled -- each
     link carries data-desc and the panel carries one region that shows the
     description of whatever the pointer or keyboard is on.

     The default text is kept on the region itself so a panel can word its own
     resting state. Nothing here runs when the markup is absent, so the other
     panels are unaffected. */
  function setupHints(menu) {
    var hints = Array.prototype.slice.call(menu.querySelectorAll('[data-mgdb-hint]'));

    hints.forEach(function (hint) {
      var panel = hint.closest ? hint.closest('[class*="dropdown_"]') : null;
      if (!panel) { return; }

      var fallback = hint.getAttribute('data-mgdb-hint-default') || '';
      var described = Array.prototype.slice.call(panel.querySelectorAll('[data-desc]'));
      if (!described.length) { return; }

      function show(text) {
        if (text) {
          hint.textContent = text;
          hint.setAttribute('data-mgdb-hint-active', '');
        } else {
          hint.textContent = fallback;
          hint.removeAttribute('data-mgdb-hint-active');
        }
      }

      described.forEach(function (link) {
        var desc = link.getAttribute('data-desc');
        link.addEventListener('mouseenter', function () { show(desc); });
        link.addEventListener('focus', function () { show(desc); });
      });

      // Resetting per-link on mouseleave would flicker while crossing the gap
      // between two rows; reset once, when the pointer leaves the whole panel.
      panel.addEventListener('mouseleave', function () { show(''); });
      panel.addEventListener('focusout', function () {
        window.setTimeout(function () {
          if (!panel.contains(document.activeElement)) { show(''); }
        }, 0);
      });
    });
  }

  function init() {
    var menu = document.getElementById('menu_bar');
    if (!menu) { return; }

    setupNavToggle(menu);
    setupMenuTriggers(menu);
    setupHints(menu);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
