/* file: mgdb-bin-viewer.js
 *
 * purpose: behavior for the Bin Viewer (/bin_viewer).
 *
 *   - the destination switch over the idiogram, which rewrites every bin's
 *     href between the bin pages and the genome browser
 *   - the hover and focus readout beside the idiogram
 *   - the chromosome selector over the core bin marker tables
 *   - the data sections on a bin or chromosome page, fetched from the same
 *     endpoints the previous page used
 *
 * The idiogram itself is rendered server side, so the page is navigable with
 * no script at all: every bin is already a link to its bin page.
 *
 * Bauplan's includeScript emits into <head>, so the entry point waits for
 * DOMContentLoaded or every query below returns nothing.
 */

(function () {
  'use strict';

  function byId(id) { return document.getElementById(id); }

  function esc(value) {
    if (value === null || value === undefined) { return ''; }
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function num(value) { return Number(value || 0).toLocaleString(); }

  /* ======================================================================
     Destination switch

     The previous page drew the idiogram twice, once linking to bin pages and
     once to the genome browser, from the same JPEG with two image maps over
     it. One map with a switch says the same thing and halves what a reader has
     to look at. Both wordings are kept as the option labels.
     ====================================================================== */

  function initDestination() {
    var radios = document.querySelectorAll('input[name="bin-destination"]');
    if (!radios.length) { return; }

    var svg = document.querySelector('.bin-idiogram');
    if (!svg) { return; }

    var gbrowse = svg.getAttribute('data-gbrowse-url') || '';

    function apply(destination) {
      Array.prototype.forEach.call(svg.querySelectorAll('a.bin-link'), function (link) {
        var cell = link.querySelector('.bin-cell');
        if (!cell) { return; }
        var label = cell.getAttribute('data-bin');
        if (destination === 'gbrowse' && gbrowse) {
          link.setAttribute('href', gbrowse + '?name=' + encodeURIComponent(label));
        } else {
          link.setAttribute('href', '/bin_viewer?fullbin=' + encodeURIComponent(label));
        }
      });
    }

    Array.prototype.forEach.call(radios, function (radio) {
      radio.addEventListener('change', function () {
        if (radio.checked) { apply(radio.value); }
      });
      if (radio.checked) { apply(radio.value); }
    });
  }

  /* ======================================================================
     Readout

     Replaces the native title tooltip, which never appears on a touch device
     and is not reachable from the keyboard. The <title> elements stay in the
     SVG as the accessible name; this is the visible version.
     ====================================================================== */

  function initReadout() {
    var readout = byId('bin-readout');
    var svg = document.querySelector('.bin-idiogram');
    if (!readout || !svg) { return; }

    var resting = '<p class="mgdb-small mgdb-muted">Point at a bin, or move through them with the keyboard, to see how many mapped loci it holds.</p>';
    readout.innerHTML = resting;

    function show(cell) {
      var label = cell.getAttribute('data-bin');
      var loci = parseInt(cell.getAttribute('data-loci'), 10) || 0;
      var parts = label.split('.');

      readout.innerHTML =
        '<h3>Bin ' + esc(label) + '</h3>'
        + '<p>Chromosome ' + esc(parts[0]) + ', region ' + esc(parts[1]) + '.</p>'
        + '<p>' + (loci > 0
            ? num(loci) + ' mapped loc' + (loci === 1 ? 'us' : 'i')
            : 'No mapped loci') + ' in this bin.</p>'
        + '<p><a href="/bin_viewer?fullbin=' + esc(label) + '">Open bin ' + esc(label) + '</a></p>';
    }

    function clear() { readout.innerHTML = resting; }

    Array.prototype.forEach.call(svg.querySelectorAll('.bin-cell'), function (cell) {
      var link = cell.closest ? cell.closest('a') : null;
      var target = link || cell;
      target.addEventListener('mouseenter', function () { show(cell); });
      target.addEventListener('focus', function () { show(cell); });
    });

    svg.addEventListener('mouseleave', clear);
  }

  /* ======================================================================
     Core bin marker chromosome selector
     ====================================================================== */

  function initMarkerTabs() {
    var tabs = document.querySelectorAll('.bin-chr-tab');
    if (!tabs.length) { return; }

    function select(chromosome) {
      Array.prototype.forEach.call(tabs, function (tab) {
        var current = tab.getAttribute('data-chromosome') === String(chromosome);
        tab.classList.toggle('is-current', current);
        tab.setAttribute('aria-selected', current ? 'true' : 'false');
      });
      Array.prototype.forEach.call(document.querySelectorAll('.bin-marker-panel'), function (panel) {
        panel.hidden = panel.id !== 'bin-markers-chr' + chromosome;
      });
    }

    Array.prototype.forEach.call(tabs, function (tab) {
      tab.addEventListener('click', function () {
        select(tab.getAttribute('data-chromosome'));
      });
    });

    // Left and right arrows move between chromosomes, as a tablist should.
    Array.prototype.forEach.call(tabs, function (tab, index) {
      tab.addEventListener('keydown', function (event) {
        var next = null;
        if (event.key === 'ArrowRight') { next = tabs[(index + 1) % tabs.length]; }
        if (event.key === 'ArrowLeft') { next = tabs[(index - 1 + tabs.length) % tabs.length]; }
        if (!next) { return; }
        event.preventDefault();
        next.focus();
        select(next.getAttribute('data-chromosome'));
      });
    });
  }

  /* ======================================================================
     Data sections on a bin or chromosome page

     Same endpoints the previous page called:
       record_data/bin_viewer_data.php?type=<section>&bin=N&sub=NN
       record_data/chromosome_data.php?type=<section>&id=N

     Two differences. The previous page fired all nine at once on load; these
     are fetched one at a time in order, so a slow section cannot hold up the
     ones above it and the database sees one query at a time rather than nine.
     And the URLs carry nomaps=1, which tells those endpoints to skip the
     image-map partial they would otherwise render into every section -- 21 KB
     of <area> tags per response, 171 KB across a bin page, for markup this
     page does not use. trimResponse below is the belt to that braces: if an
     endpoint ever answers without honouring the parameter, the maps still do
     not reach the DOM.
     ====================================================================== */

  function trimResponse(html) {
    // Everything up to and including the last </map> is image-map markup. With
    // nomaps=1 there is none and this does nothing.
    var end = html.lastIndexOf('</map>');
    if (end !== -1) { html = html.slice(end + 6); }
    return html;
  }

  function loadSection(container) {
    var url = container.getAttribute('data-url');
    if (!url) { return Promise.resolve(); }

    container.innerHTML = '<div class="bin-loading"><span class="mgdb-spinner" aria-hidden="true"></span>Loading&hellip;</div>';

    return fetch(url, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) { throw new Error('status ' + response.status); }
        return response.text();
      })
      .then(function (text) {
        var body = trimResponse(text).trim();
        container.innerHTML = body || '<p class="mgdb-muted">No data in this section.</p>';
      })
      .catch(function () {
        container.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">'
          + 'This section could not be loaded. <a href="' + esc(url) + '">Open it directly</a>.</div>';
      });
  }

  function initSections() {
    var containers = document.querySelectorAll('.bin-section-body[data-url]');
    if (!containers.length) { return; }

    // One at a time, in document order.
    Array.prototype.reduce.call(containers, function (chain, container) {
      return chain.then(function () { return loadSection(container); });
    }, Promise.resolve());
  }

  /* ======================================================================
     Section tab scrollspy

     Driven by three triggers, because no single one is reliable everywhere:
     a scroll listener, an IntersectionObserver, and a resize. Some embedded
     and backgrounded browsers deliver no scroll events at all, and others
     deliver no observer entries; each trigger calls the same update, and
     whichever one works wins.

     The line is measured from the sticky tab bar rather than hardcoded. On a
     bin page the bar carries ten long section names and wraps to two or three
     rows, so a fixed offset would mark the wrong tab at exactly the width
     where the bar is tallest.
     ====================================================================== */

  function initScrollspy() {
    var nav = document.querySelector('.mgdb-bin-page .mgdb-section-tabs');
    if (!nav) { return; }

    var links = nav.querySelectorAll('a[href^="#"]');
    if (!links.length) { return; }

    var entries = [];
    Array.prototype.forEach.call(links, function (link) {
      var target = document.getElementById(link.getAttribute('href').slice(1));
      if (target) { entries.push({ link: link, target: target }); }
    });
    if (!entries.length) { return; }

    var pinned = null;
    var pinnedAt = 0;

    function select(entry) {
      entries.forEach(function (e) {
        e.link.classList.toggle('is-current', e === entry);
      });

      // Keep the marked tab reachable when the bar scrolls sideways rather
      // than wrapping.
      if (nav.scrollWidth > nav.clientWidth + 2) {
        var barBox = nav.getBoundingClientRect();
        var tabBox = entry.link.getBoundingClientRect();
        if (tabBox.left < barBox.left || tabBox.right > barBox.right) {
          nav.scrollLeft += tabBox.left - barBox.left - 16;
        }
      }
    }

    /* The line a section has to cross to count as current.

       It has to agree with scroll-margin-top, or clicking a tab marks the
       section above the one it jumped to: the browser parks the heading
       scroll-margin-top below the viewport top, and if the spy's line sits
       higher than that the section has not "arrived" yet. Reading the value
       off the section keeps the two in step whatever the stylesheet says. The
       sticky bar height is the floor, for the case where the margin is small.  */
    function currentLine() {
      var margin = parseFloat(window.getComputedStyle(entries[0].target).scrollMarginTop);
      if (!isFinite(margin)) { margin = 0; }
      return Math.max(nav.getBoundingClientRect().height + 8, margin + 4);
    }

    function update() {
      if (pinned) {
        if (Math.abs(window.scrollY - pinnedAt) < 24) { return; }
        pinned = null;
      }

      var line = currentLine();

      var current = entries[0];
      entries.forEach(function (entry) {
        if (entry.target.getBoundingClientRect().top <= line) { current = entry; }
      });

      /* At the bottom of the page the last sections may never cross the line,
         because there is no scroll left to bring them up to it. Whatever is
         nearest the bottom of the viewport is what the reader is looking at. */
      var doc = document.documentElement;
      if (window.innerHeight + window.scrollY >= doc.scrollHeight - 4) {
        current = entries[entries.length - 1];
      }

      select(current);
    }

    /* A click marks its tab at once and holds it until the reader scrolls
       away, for two reasons. The spy would otherwise fight the scroll the
       click itself caused; and at the very bottom of the page the last two
       sections are both fully on screen, so landing on the second-to-last one
       would immediately re-mark the last. Releasing on the reader's own scroll
       rather than on a timer keeps the tab they chose marked. */
    entries.forEach(function (entry) {
      entry.link.addEventListener('click', function () {
        select(entry);
        pinned = entry;
        pinnedAt = window.scrollY;
        // Release once the browser has finished scrolling there.
        window.setTimeout(function () { pinnedAt = window.scrollY; }, 700);
      });
    });

    var scheduled = false;
    function schedule() {
      if (scheduled) { return; }
      scheduled = true;
      window.setTimeout(function () { scheduled = false; update(); }, 100);
    }

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);

    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(schedule, {
        threshold: 0,
        rootMargin: '0px 0px -60% 0px'
      });
      entries.forEach(function (entry) { observer.observe(entry.target); });
    }

    /* The data sections of a bin page arrive after the page paints and change
       every section's position as they land. */
    if (window.MutationObserver) {
      var bodies = document.querySelectorAll('.bin-section-body');
      if (bodies.length) {
        var mutations = new window.MutationObserver(schedule);
        Array.prototype.forEach.call(bodies, function (body) {
          mutations.observe(body, { childList: true });
        });
      }
    }

    update();
  }

  /* ====================================================================== */

  function init() {
    initDestination();
    initReadout();
    initMarkerTabs();
    initSections();
    initScrollspy();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
