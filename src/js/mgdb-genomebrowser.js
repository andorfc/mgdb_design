/**
 * file: js/mgdb-genomebrowser.js
 *
 * purpose: Scrollspy, launcher submit handlers, and live search for Genome Browser Data Hub
 */

(function () {
  'use strict';

  function init() {
    initScrollSpy();
    initDirectoryFilter();
    initNamExample();
    initLightbox();
  }

  /* The tab bar. This page carried its own IntersectionObserver-only spy, which
     is one of the shapes that shipped broken elsewhere: a single trigger does
     not fire in every case, there is no bottom-of-page case, and a clicked tab
     is not held while the browser is still scrolling to it. MGDB.sectionTabs()
     is that behaviour written once, driven by scroll, IntersectionObserver and
     resize together. */
  function initScrollSpy() {
    if (window.MGDB && window.MGDB.sectionTabs) {
      window.MGDB.sectionTabs({ watch: '#browser-dir-table' });
    }
  }

  function initDirectoryFilter() {
    var searchInput = document.getElementById('browser-dir-search');
    var filterChips = document.querySelectorAll('.dir-filter-chip');
    var rows = document.querySelectorAll('#browser-dir-table tbody tr');

    if (!rows.length) return;

    var activePlatform = 'all';
    var searchQuery = '';

    function applyFilter() {
      var q = searchQuery.toLowerCase().trim();

      rows.forEach(function (row) {
        var rowType = (row.getAttribute('data-type') || '').toLowerCase();
        var text = row.textContent.toLowerCase();

        var matchesPlatform = (activePlatform === 'all' || rowType.indexOf(activePlatform) !== -1);
        var matchesSearch = (q === '' || text.indexOf(q) !== -1);

        if (matchesPlatform && matchesSearch) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    }

    filterChips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        filterChips.forEach(function (c) { c.classList.remove('is-active'); });
        chip.classList.add('is-active');
        activePlatform = (chip.getAttribute('data-filter') || 'all').toLowerCase();
        applyFilter();
      });
    });

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        searchQuery = searchInput.value;
        applyFilter();
      });
    }
  }

  // Global launcher helpers
  window.launchJBrowse2Linear = function () {
    var form = document.getElementById('jbrowse2_linear_form');
    if (!form) return;

    var asm1 = document.getElementById('linear_primary_assembly');
    var chr = document.getElementById('linear_chr');
    var start = document.getElementById('linear_start');
    var end = document.getElementById('linear_end');

    var asm1Val = asm1 ? asm1.value : 'Zm-B73-REFERENCE-NAM-5.0';
    var chrVal = chr ? chr.value : 'chr1';
    var startVal = start && start.value ? start.value.trim() : '';
    var endVal = end && end.value ? end.value.trim() : '';

    var targetUrl = 'https://jbrowse2.maizegdb.org';
    if (startVal !== '' && endVal !== '') {
      targetUrl += '/?loc=' + encodeURIComponent(chrVal + ':' + startVal + '..' + endVal) + '&assembly=' + encodeURIComponent(asm1Val);
      window.open(targetUrl, '_blank', 'noopener');
    } else {
      form.submit();
    }
  };

  window.launchJBrowse2Synteny = function () {
    var form = document.getElementById('jbrowse2_synteny_form');
    if (form) {
      form.submit();
    }
  };

  /* B73 v5 gene model against every other NAM founder alignment track.

     Ported from the pre-redesign js/genomebrowser.js, which this page had
     dropped. The gene name is NOT passed to JBrowse 2 as a parameter: its
     search index is the one built for JBrowse 1 and errors on a gene name, so
     the coordinates are looked up first and a location is passed instead. That
     workaround is the reason for the fetch; if the native JBrowse 2 search
     adapter ever lands, this collapses to a single URL. */
  var NAM_ALIGNMENT_TRACKS = [
    'B73v5_gene_models_official',
    'B73v5_to_B97_pif', 'B73v5_to_CML103_pif', 'B73v5_to_CML228_pif',
    'B73v5_to_CML247_pif', 'B73v5_to_CML277_pif', 'B73v5_to_CML322_pif',
    'B73v5_to_CML333_pif', 'B73v5_to_CML52_pif', 'B73v5_to_CML69_pif',
    'B73v5_to_HP301_pif', 'B73v5_to_Il14H_pif', 'B73v5_to_Ki11_pif',
    'B73v5_to_Ki3_pif', 'B73v5_to_Ky21_pif', 'B73v5_to_M162W_pif',
    'B73v5_to_M37W_pif', 'B73v5_to_Mo18W_pif', 'B73v5_to_Ms71_pif',
    'B73v5_to_NC350_pif', 'B73v5_to_NC358_pif', 'B73v5_to_Oh43_pif',
    'B73v5_to_Oh7B_pif', 'B73v5_to_P39_pif', 'B73v5_to_Tx303_pif',
    'B73v5_to_Tzi8_pif'
  ];

  function namAlignmentStatus(message) {
    var el = document.getElementById('nam-alignment-status');
    if (!el) { return; }
    el.textContent = message || '';
    el.hidden = !message;
  }

  window.launchJBrowse2NAMAlignments = function () {
    var input = document.getElementById('alignment_gm');
    var gene = input && input.value ? input.value.trim() : '';

    if (gene === '') {
      namAlignmentStatus('Enter a B73 v5 gene model ID first.');
      if (input) { input.focus(); }
      return;
    }

    namAlignmentStatus('Looking up ' + gene + '\u2026');

    fetch('https://jbrowse.maizegdb.org/api/genes/' + encodeURIComponent(gene))
      .then(function (response) {
        if (!response.ok) { throw new Error('HTTP ' + response.status); }
        return response.json();
      })
      .then(function (info) {
        if (!info || !info.seqid) { throw new Error('no coordinates'); }
        var buffer = 1000;
        var loc = info.seqid + ':' + (parseInt(info.start, 10) - buffer) +
                  '-' + (parseInt(info.end, 10) + buffer);
        var spec = JSON.stringify({
          views: [{
            assembly: 'Zm-B73-REFERENCE-NAM-5.0',
            loc: loc,
            type: 'LinearGenomeView',
            tracks: NAM_ALIGNMENT_TRACKS
          }]
        });
        namAlignmentStatus('');
        window.open('https://jbrowse2.maizegdb.org/?session=spec-' + spec, '_blank', 'noopener');
      })
      .catch(function () {
        namAlignmentStatus('No B73 v5 gene model found for "' + gene + '". Check the identifier and try again.');
      });
  };

  /* The example identifier fills the box rather than launching, so a reader can
     see what a gene model ID looks like before committing to a new tab. */
  function initNamExample() {
    var example = document.querySelector('.launcher-example[data-gene]');
    var input = document.getElementById('alignment_gm');
    if (!example || !input) { return; }
    example.addEventListener('click', function () {
      input.value = example.getAttribute('data-gene') || '';
      namAlignmentStatus('');
      input.focus();
    });
  }

  /* Screenshot enlargement, which the pre-redesign page had through Shadowbox
     and this one had lost. One dialog, reused by both figures. */
  function initLightbox() {
    var dialog = document.getElementById('browser-lightbox');
    var img = document.getElementById('browser-lightbox-img');
    var caption = document.getElementById('browser-lightbox-caption');
    var close = document.getElementById('browser-lightbox-close');
    var zooms = document.querySelectorAll('.guide-diagram-zoom[data-full]');
    if (!dialog || !img || !zooms.length || !dialog.showModal) { return; }

    Array.prototype.forEach.call(zooms, function (zoom) {
      zoom.addEventListener('click', function () {
        var text = zoom.getAttribute('data-caption') || '';
        img.setAttribute('src', zoom.getAttribute('data-full'));
        img.setAttribute('alt', text);
        if (caption) { caption.textContent = text; }
        dialog.showModal();
      });
    });

    if (close) {
      close.addEventListener('click', function () { dialog.close(); });
    }

    // Clicking the backdrop closes it: the dialog element itself is the
    // backdrop's hit target, so a click that did not land on its contents.
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) { dialog.close(); }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
