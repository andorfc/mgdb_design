/* ==========================================================================
 * file: mgdb-whole-genome.js
 * purpose: Interactive assembly & chromosome image visualizer for Whole Genome Views
 * ========================================================================== */

(function () {
  'use strict';

  var annotations = {
    'B73v5'  : 'Zm00001eb.1',
    'B97'    : 'Zm00018ab.1',
    'CML52'  : 'Zm00019ab.1',
    'CML69'  : 'Zm00020ab.1',
    'CML103' : 'Zm00021ab.1',
    'CML228' : 'Zm00022ab.1',
    'CML247' : 'Zm00023ab.1',
    'CML277' : 'Zm00024ab.1',
    'CML322' : 'Zm00025ab.1',
    'CML333' : 'Zm00026ab.1',
    'HP301'  : 'Zm00027ab.1',
    'Il14H'  : 'Zm00028ab.1',
    'Ki3'    : 'Zm00029ab.1',
    'Ki11'   : 'Zm00030ab.1',
    'Ky21'   : 'Zm00031ab.1',
    'M37W'   : 'Zm00032ab.1',
    'M162W'  : 'Zm00033ab.1',
    'Mo18W'  : 'Zm00034ab.1',
    'Ms71'   : 'Zm00035ab.1',
    'NC350'  : 'Zm00036ab.1',
    'NC358'  : 'Zm00037ab.1',
    'Oh7B'   : 'Zm00038ab.1',
    'Oh43'   : 'Zm00039ab.1',
    'P39'    : 'Zm00040ab.1',
    'Tx303'  : 'Zm00041ab.1',
    'Tzi8'   : 'Zm00042ab.1'
  };

  function initWholeGenomeViewer() {
    // Assembly selector
    var assemblySelect = document.getElementById('select-assembly-input');
    var mainImage = document.getElementById('wg-main-image');
    var titleSpan = document.getElementById('wg-display-title');
    var annotSpan = document.getElementById('wg-display-annot');
    var dlPngBtn = document.getElementById('btn-dl-png');
    var dlSvgBtn = document.getElementById('btn-dl-svg');

    if (assemblySelect) {
      assemblySelect.addEventListener('change', function () {
        var key = assemblySelect.value;
        var optText = assemblySelect.options[assemblySelect.selectedIndex].text;
        var annot = annotations[key] || 'Annotated Set';

        if (mainImage) mainImage.src = '/images/NAM/' + key + '.png';
        if (titleSpan) titleSpan.textContent = optText;
        if (annotSpan) annotSpan.textContent = annot;
        if (dlPngBtn) dlPngBtn.href = '/images/NAM/' + key + '.png';
        if (dlSvgBtn) dlSvgBtn.href = '/images/NAM/' + key + '.svg';
      });
    }

    // Chromosome chips
    var chrChips = document.querySelectorAll('.wg-chr-chip');
    var chrImage = document.getElementById('wg-chr-image');
    var chrTitle = document.getElementById('wg-chr-display-title');
    var dlChrPngBtn = document.getElementById('btn-dl-chr-png');
    var dlChrSvgBtn = document.getElementById('btn-dl-chr-svg');

    chrChips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        var chr = chip.getAttribute('data-chr');
        var chrNum = chr.replace('chr', '');

        chrChips.forEach(function (c) { c.classList.remove('is-active'); });
        chip.classList.add('is-active');

        if (chrImage) chrImage.src = '/images/NAM/' + chr + '.png';
        if (chrTitle) chrTitle.textContent = 'Chromosome ' + chrNum + ' Across 26 NAM Founder Assemblies';
        if (dlChrPngBtn) dlChrPngBtn.href = '/images/NAM/' + chr + '.png';
        if (dlChrSvgBtn) dlChrSvgBtn.href = '/images/NAM/' + chr + '.svg';
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWholeGenomeViewer);
  } else {
    initWholeGenomeViewer();
  }
})();
