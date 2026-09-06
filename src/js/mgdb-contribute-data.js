/**
 * file: js/mgdb-contribute-data.js
 *
 * purpose: behaviour for How to Contribute Data (/contribute_data).
 *
 * The section tab bar is markup the shell styles; the scrollspy is not wired
 * automatically, because pages carrying their own copy would then run two
 * spies over one bar and fight over the click hold. Opt in here.
 *
 * Also opens the FAQ a fragment points at. The six FAQs are <details>, so a
 * link to #contribute_data_faq3 would otherwise scroll to a collapsed row and
 * appear to do nothing -- and those anchors are the ones the pre-redesign page
 * published, so external links to them exist.
 */

(function () {
  'use strict';

  function openTargetedFaq() {
    var hash = window.location.hash;
    if (!hash || hash.length < 2) { return; }

    var target = document.getElementById(hash.slice(1));
    if (!target) { return; }

    var faq = target.closest ? target.closest('details.contribute-faq') : null;
    if (faq && !faq.open) {
      faq.open = true;
      /* The row was collapsed when the browser computed where to scroll, so
         its position has moved; put it back under the sticky bar. */
      target.scrollIntoView({ block: 'start' });
    }
  }

  function init() {
    if (window.MGDB && typeof window.MGDB.sectionTabs === 'function') {
      window.MGDB.sectionTabs();
    }
    openTargetedFaq();
    window.addEventListener('hashchange', openTargetedFaq);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
