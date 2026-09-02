/* Nomenclature — /nomenclature
   The shared section-tab behaviour. The page had no script at all: the tab bar
   was styled by the shell and its links scrolled, but nothing ever moved the
   active state off the first tab, so the bar looked dead whichever tab you
   clicked. */

(function () {
  'use strict';

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

    update();
  }

  /* The complete standard is nested from the curator's template and carries 23
     <a name> anchors. Following one of those leaves the tab bar showing
     whichever section the reader came from, because an <a name> is not a
     section the bar knows about -- so after such a jump the bar is re-synced to
     wherever the page actually landed. */
  function trackNestedAnchors() {
    var standard = document.getElementById('complete-standard');
    if (!standard) { return; }
    document.addEventListener('click', function (event) {
      var link = event.target.closest ? event.target.closest('a[href^="#"]') : null;
      if (!link || link.closest('.mgdb-section-tabs')) { return; }
      window.setTimeout(function () {
        window.dispatchEvent(new Event('scroll'));
      }, 400);
    });
  }

  function init() {
    buildTabs();
    trackNestedAnchors();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
