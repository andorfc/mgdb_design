/* mgdb-mgec.js -- /mgec
 *
 * The whole record is server-rendered, so the only behaviour here is the
 * section-tab scrollspy and one thing the disclosure needs: a deep link into a
 * year whose content is inside a collapsed <details> has to open it, or the
 * reader lands on a summary line and sees nothing they asked for.
 *
 * Bauplan::includeScript emits into <head>, so nothing below may touch the
 * document until it has been parsed.
 */
(function () {
  'use strict';

  /* /mgec/activities2011 redirects to /mgec#mgec-activities2011, and the 2011
     minutes live in a <details>. Open any disclosure inside the targeted
     element -- and any the element sits inside -- then re-scroll, because the
     browser measured the anchor's position while it was still collapsed. */
  function openTargetedDetails() {
    var id = (window.location.hash || '').slice(1);
    if (!id) { return; }
    var target;
    try { target = document.getElementById(decodeURIComponent(id)); }
    catch (error) { target = document.getElementById(id); }
    if (!target) { return; }

    var opened = false;
    var ancestor = target.closest ? target.closest('details') : null;
    while (ancestor) {
      if (!ancestor.open) { ancestor.open = true; opened = true; }
      ancestor = ancestor.parentNode && ancestor.parentNode.closest
        ? ancestor.parentNode.closest('details') : null;
    }
    Array.prototype.forEach.call(target.querySelectorAll('details'), function (details) {
      if (!details.open) { details.open = true; opened = true; }
    });

    if (opened) {
      /* scrollIntoView honours the section's scroll-margin-top, so this lands
         where a tab click lands rather than under the sticky bar. */
      target.scrollIntoView({ block: 'start', behavior: 'auto' });
    }
  }

  /* ── Section tabs ──────────────────────────────────────────────────────── */

  /* The trigger line is read back from the section's own scroll-margin-top, so
     the line the spy marks a section at and the offset a click parks it at
     agree by construction. */
  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) { return; }

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href') || '';
      if (href.charAt(0) !== '#') { return; }
      var section = document.getElementById(href.slice(1));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { return; }

    var heldUntilScroll = null;
    var heldAtY = 0;

    function mark(section) {
      pairs.forEach(function (pair) {
        var current = pair.section === section;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    function triggerLine() {
      var bar = document.querySelector('.mgdb-section-tabs');
      var barHeight = bar ? bar.getBoundingClientRect().height : 0;
      var margin = parseFloat(window.getComputedStyle(pairs[0].section).scrollMarginTop) || 0;
      return Math.max(barHeight + 8, margin + 4);
    }

    function update() {
      if (heldUntilScroll) {
        if (Math.abs(window.scrollY - heldAtY) < 4) { return; }
        heldUntilScroll = null;
      }
      var line = triggerLine();
      var current = pairs[0];
      pairs.forEach(function (pair) {
        if (pair.section.hasAttribute('hidden')) { return; }
        if (pair.section.getBoundingClientRect().top <= line) { current = pair; }
      });
      /* Without a bottom-of-page case the last section never highlights: it is
         shorter than the viewport, so its top never reaches the line. */
      if ((window.innerHeight + window.scrollY) >= (document.documentElement.scrollHeight - 2)) {
        current = pairs[pairs.length - 1];
      }
      if (current) { mark(current.section); }
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () {
        mark(pair.section);
        heldUntilScroll = pair.section;
        heldAtY = window.scrollY;
      });
    });

    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);

    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(function () { update(); },
        { rootMargin: '-20% 0px -60% 0px' });
      pairs.forEach(function (pair) { observer.observe(pair.section); });
    }

    /* Opening the minutes moves every section below Activities. */
    Array.prototype.forEach.call(document.querySelectorAll('details'), function (details) {
      details.addEventListener('toggle', update);
    });

    /* A fragment arrival is scrolled by the browser outside these listeners. */
    if (document.readyState !== 'complete') {
      window.addEventListener('load', function () { window.setTimeout(update, 0); });
    }

    update();
  }

  function init() {
    openTargetedDetails();
    buildTabs();
    window.addEventListener('hashchange', openTargetedDetails);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
