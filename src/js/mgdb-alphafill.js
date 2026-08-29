/* ==========================================================================
   /data_center/alphafill — ligand transplants over predicted maize structures
   --------------------------------------------------------------------------
   Five jobs, deliberately not coupled:

     the gene view    asks search/alphafill/alphafill_api.php for one gene and
                      renders its collapsed ligand list
     the viewer       loads the AlphaFold model and the transplanted-ligand
                      coordinates as two 3Dmol models and draws them together
     the ligand view  the inverted index: every gene predicted to bind one
                      compound
     the browse table the whole tier-1 index in one request, filtered locally
     the target list  confident pocket, no qualifying donor

   Why the viewer loads two files
   ------------------------------
   Ligands are 4.6% of the atoms in a filled AlphaFill mmCIF, and the protein
   half is byte-identical to the AlphaFold model published beside it. So the
   protein comes from /data/alphafill/models/ and the transplants from
   /data/alphafill/lig/ as a coordinates-only mmCIF. Loading them as separate
   3Dmol models is not just a size win: pLDDT colouring applies to model 0 and
   per-transplant colouring to the rest, and an individual transplant can be
   shown, hidden or focused without re-fetching anything.

   Each transplant is split into its own 3Dmol model, keyed by the mmCIF
   label_asym_id that AlphaFill's own metadata names. Without that split every
   ligand would share one style and the cards could not address them.

   The pLDDT strip is derived from the model's B-factor column — one CA atom per
   residue — rather than shipped alongside it, the same way the protein
   structure page does it. Nothing has to be precomputed.

   Three empty states, never one
   -----------------------------
   'transplant', 'no_donor' and 'no_model' get three different renderings. The
   middle one is 21,427 genes and reads "no PDB homolog cleared the identity
   floor", which is a statement about the databank, not about the protein.
   Collapsing them into "no results" is the mistake this page exists to avoid.

   If this file fails to load, or WebGL is unavailable, the page keeps its
   documentation, its counts, its downloads and its links, and the template
   says so in a <noscript>.

   Nothing here touches the DOM before DOMContentLoaded. Bauplan emits every
   includeScript() into <head>, so a top-level querySelector runs while <main>
   does not yet exist and returns null.

   Depends on MGDB from /js/mgdb-modern.js and $3Dmol from /js/lib/3dmol/.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var API = '/search/alphafill/alphafill_api.php';
  var INDEX_URL = '/data/alphafill/index.json';
  var escape = MGDB.escapeHtml;

  /* Resolved in init(), never before. */
  var els = {};

  var state = {
    gene: null,          /* the gene payload currently shown */
    detail: null,        /* its raw transplants, once fetched */
    pockets: [],
    activeCcd: null,
    viewer: null,
    ligModels: {},       /* asym id -> 3Dmol model */
    profile: null,       /* per-residue pLDDT, from the B-factor column */
    index: null,         /* the tier-1 browse index */
    browseFilters: { strong: true, moderate: true, ion: false, weak: false, additive: false },
    browsePocketOnly: false
  };

  var EVIDENCE = ['strong', 'moderate', 'ion', 'weak', 'additive'];
  var EVIDENCE_LABEL = {
    strong: 'strong', moderate: 'moderate', ion: 'ion',
    weak: 'weak', additive: 'additive'
  };

  /* The published AlphaFill confidence bands. Restated here so a badge and the
     number beside it can never disagree. */
  var BAND_LRMSD = [0.92, 3.10];
  var BAND_TCS = [0.64, 1.27];

  /* --------------------------------------------------------------------- *
   * Small helpers
   * --------------------------------------------------------------------- */

  function num(value, digits) {
    if (value === null || value === undefined || isNaN(value)) { return '—'; }
    return Number(value).toFixed(digits === undefined ? 2 : digits);
  }

  function intFmt(value) {
    if (value === null || value === undefined || isNaN(value)) { return '—'; }
    return Number(value).toLocaleString('en-US');
  }

  function bandClass(value, bands) {
    if (value === null || value === undefined || isNaN(value)) { return ''; }
    if (value < bands[0]) { return 'is-high'; }
    if (value <= bands[1]) { return 'is-mid'; }
    return 'is-low';
  }

  function identityClass(value) {
    if (value === null || value === undefined) { return ''; }
    if (value >= 0.5) { return 'is-high'; }
    if (value >= 0.3) { return 'is-mid'; }
    return 'is-low';
  }

  /* AlphaFold's own palette, so the viewer matches every other tool the
     audience uses and the legend matches what is drawn. */
  function plddtColor(value) {
    if (value > 90) { return 0x0053d6; }
    if (value >= 70) { return 0x65cbf3; }
    if (value >= 50) { return 0xffdb13; }
    return 0xff7d45;
  }

  var LIGAND_PALETTE = [
    0xd6336c, 0x0b7285, 0xe8590c, 0x5f3dc4, 0x2b8a3e, 0xc2255c,
    0x1864ab, 0xd9480f, 0x364fc7, 0x087f5b, 0x862e9e, 0xa61e4d
  ];

  function ligandColor(index) {
    return LIGAND_PALETTE[index % LIGAND_PALETTE.length];
  }

  function hex(value) {
    return '#' + value.toString(16).padStart(6, '0');
  }

  function geneLink(gene) {
    return '<a class="af-table-gene" href="/gene_center/gene?id=' + encodeURIComponent(gene)
         + '">' + escape(gene) + '</a>';
  }

  function badge(evidence) {
    if (!evidence) { return '<span class="af-badge af-badge-none">no donor</span>'; }
    return '<span class="af-badge af-badge-' + escape(evidence) + '">'
         + escape(EVIDENCE_LABEL[evidence] || evidence) + '</span>';
  }

  /* --------------------------------------------------------------------- *
   * Typeahead
   * --------------------------------------------------------------------- */

  function closeSuggestions() {
    if (!els.suggestions) { return; }
    els.suggestions.hidden = true;
    els.suggestions.innerHTML = '';
    els.searchInput.setAttribute('aria-expanded', 'false');
  }

  function renderSuggestions(payload) {
    var genes = payload.genes || [];
    var ligands = payload.ligands || [];
    if (!genes.length && !ligands.length) {
      els.suggestions.innerHTML = '<p class="mgdb-suggestions-message">'
        + 'No gene model or ligand starts with that.</p>';
      els.suggestions.hidden = false;
      return;
    }

    var html = '';
    if (genes.length) {
      html += '<p class="af-suggestion-group">Genes</p>';
      genes.forEach(function (item) {
        html += '<button class="mgdb-suggestion" type="button" role="option"'
             + ' data-af-term="' + escape(item.gene) + '">'
             + '<span class="mgdb-suggestion-copy"><b>' + escape(item.gene) + '</b>'
             + '<span class="mgdb-suggestion-recent">' + escape(item.chrom || '')
             + ' · pLDDT ' + num(item.plddt, 1) + '</span></span>'
             + '<span class="af-suggestion-meta"><b>' + item.ligands + '</b> ligands'
             + (item.strong ? ' · <b>' + item.strong + '</b> strong' : '')
             + '</span></button>';
      });
    }
    if (ligands.length) {
      html += '<p class="af-suggestion-group">Ligands</p>';
      ligands.forEach(function (item) {
        html += '<button class="mgdb-suggestion" type="button" role="option"'
             + ' data-af-ccd="' + escape(item.ccd) + '">'
             + '<span class="mgdb-suggestion-copy"><b>' + escape(item.ccd) + '</b>'
             + '<span class="mgdb-suggestion-recent">' + escape((item.name || '').toLowerCase())
             + '</span></span>'
             + '<span class="af-suggestion-meta"><b>' + intFmt(item.genes) + '</b> genes</span>'
             + '</button>';
      });
    }

    els.suggestions.innerHTML = html;
    els.suggestions.hidden = false;
    els.searchInput.setAttribute('aria-expanded', 'true');

    Array.prototype.forEach.call(els.suggestions.querySelectorAll('[data-af-term]'), function (button) {
      button.addEventListener('click', function () {
        els.searchInput.value = button.getAttribute('data-af-term');
        closeSuggestions();
        loadGene(button.getAttribute('data-af-term'));
      });
    });
    Array.prototype.forEach.call(els.suggestions.querySelectorAll('[data-af-ccd]'), function (button) {
      button.addEventListener('click', function () {
        closeSuggestions();
        loadLigand(button.getAttribute('data-af-ccd'));
      });
    });
  }

  function moveSuggestion(delta) {
    if (!els.suggestions || els.suggestions.hidden) { return; }
    var items = els.suggestions.querySelectorAll('.mgdb-suggestion');
    if (!items.length) { return; }
    var current = -1;
    for (var i = 0; i < items.length; i++) {
      if (items[i] === document.activeElement) { current = i; break; }
    }
    var next = current + delta;
    if (next < 0) { next = items.length - 1; }
    if (next >= items.length) { next = 0; }
    items[next].focus();
  }

  /* --------------------------------------------------------------------- *
   * Gene view
   * --------------------------------------------------------------------- */

  function stateMarkup(data) {
    var gene = data.gene || {};
    if (data.state === 'no_donor') {
      var pocketLine = gene.np
        ? ' P2Rank still predicts <b>' + gene.np + ' confident pocket'
          + (gene.np > 1 ? 's' : '') + '</b>'
          + (gene.tp ? ' (best probability ' + num(gene.tp, 3) + ')' : '')
          + ', so structure says there is a site here and the databank has nothing to fill it from.'
        : '';
      return '<div class="af-state af-state-no-donor">'
        + '<h3>Ran, and found no qualifying donor</h3>'
        + '<p>AlphaFill processed the model for <b>' + escape(gene.g || '') + '</b>'
        + ' and no PDB entry cleared its 25% sequence-identity threshold for a donor.</p>'
        + '<p><b>This is not evidence that the protein binds nothing.</b> It is a statement about '
        + 'how well the Protein Data Bank covers this protein family &mdash; 21,427 maize genes are '
        + 'in the same position.' + pocketLine + '</p>'
        + (gene.m ? '<p><a class="mgdb-button mgdb-button-secondary" href="' + escape(gene.m)
            + '" download>Download the AlphaFold model</a></p>' : '')
        + '</div>'
        /* A confident pocket with nothing to put in it is the case where the
           genomic projection is most worth having. */
        + pocketTrack(gene, data.pockets || []);
    }
    if (data.state === 'no_model') {
      return '<div class="af-state af-state-no-model">'
        + '<h3>Not analysed</h3>'
        + '<p>No AlphaFold model exists for <b>' + escape(gene.g || '') + '</b>, so AlphaFill never '
        + 'saw this protein. This is different from having run and found nothing.</p></div>';
    }
    return '<div class="af-state af-state-unknown">'
      + '<h3>Not recognised</h3><p>' + escape(data.message || 'That identifier did not resolve.')
      + '</p></div>';
  }

  function ligandCardMarkup(item, index) {
    var flags = '';
    if (item.ev === 'additive') {
      flags += '<p class="af-flag af-flag-additive"><b>Crystallization additive.</b> '
        + escape(item.ccd) + ' is a buffer, cryoprotectant or precipitant. Its position reflects '
        + 'the donor crystal’s conditions, not maize biology.</p>';
    }
    if (item.ev === 'ion') {
      flags += '<p class="af-flag af-flag-ion"><b>Single-atom ion.</b> One-atom placements are easy '
        + 'to superpose and easy to over-count &mdash; ions are frequently adventitious in the donor '
        + 'crystal.</p>';
    }
    if (item.id !== null && item.id !== undefined && item.id < 0.30) {
      flags += '<p class="af-flag af-flag-remote">Donor identity below 0.30 &mdash; the '
        + 'remote-homology regime that 37.5% of this run sits in. Treat the pose as a '
        + 'low-confidence hypothesis.</p>';
    }

    return '<button class="af-ligand-card" type="button" data-af-ccd-card="' + escape(item.ccd) + '"'
      + ' data-af-evidence="' + escape(item.ev) + '" aria-pressed="false">'
      + '<span class="af-ligand-head">'
      + '<span class="af-ligand-swatch" style="background:' + hex(ligandColor(index)) + '"></span>'
      + '<span class="af-ligand-ccd">' + escape(item.ccd) + '</span>'
      + badge(item.ev)
      + (item.p2r ? '<span class="af-badge af-badge-pocket">in a predicted pocket</span>' : '')
      + '</span>'
      + '<span class="af-ligand-name">' + escape(item.name || '') + '</span>'
      + '<span class="af-metrics">'
      + '<span class="af-metric ' + identityClass(item.id) + '"><strong>'
      + (item.id === null || item.id === undefined ? '—' : Math.round(item.id * 100) + '%')
      + '</strong><span>identity</span></span>'
      + '<span class="af-metric ' + bandClass(item.lr, BAND_LRMSD) + '"><strong>'
      + num(item.lr, 2) + '</strong><span>local RMSD</span></span>'
      + '<span class="af-metric ' + bandClass(item.tcs, BAND_TCS) + '"><strong>'
      + num(item.tcs, 2) + '</strong><span>TCS</span></span>'
      + '<span class="af-metric"><strong>' + intFmt(item.nd) + '</strong><span>donors</span></span>'
      + '</span>'
      + '<span class="af-ligand-name">Best donor <b>' + escape(item.pdb || '—') + '</b></span>'
      + flags
      + '</button>';
  }

  function renderGene(data) {
    var gene = data.gene;
    if (!gene || data.state !== 'transplant') {
      els.results.innerHTML = stateMarkup(data);
      return;
    }

    var ligands = gene.lig || [];
    var counts = gene.ev || {};
    var summary = EVIDENCE.filter(function (key) { return counts[key]; })
      .map(function (key) { return counts[key] + ' ' + EVIDENCE_LABEL[key]; })
      .join(' · ');

    /* 3,216 genes carry their transplants on a non-canonical isoform. The
       model, the residue numbering and the ligand coordinates all belong to
       that isoform, so saying which one is showing is not a footnote. */
    var isoform = gene.can
      ? ''
      : '<p class="af-isoform-note"><b>Showing isoform ' + escape(gene.p) + '.</b> '
        + 'The transplants for this gene are on that isoform, not on the canonical '
        + escape(gene.canp || '') + ' &mdash; so that is the model, the residue numbering and the '
        + 'coordinates below.</p>';

    var html = '<div class="af-identity">'
      + '<h3>' + escape(gene.g) + '</h3>'
      + '<div class="af-identity-facts">'
      + '<span>' + escape(gene.c || '') + '</span>'
      + '<span>model <b>' + escape(gene.p) + '</b></span>'
      + '<span>pLDDT <b>' + num(gene.pl, 1) + '</b></span>'
      + '<span><b>' + ligands.length + '</b> distinct ligands</span>'
      + '<span><b>' + intFmt(gene.nt) + '</b> raw transplants from <b>' + intFmt(gene.nh) + '</b> donors</span>'
      + (gene.np ? '<span><b>' + gene.np + '</b> confident P2Rank pocket'
                 + (gene.np > 1 ? 's' : '') + '</span>' : '')
      + '</div>'
      + isoform
      + '</div>';

    html += viewerMarkup(gene);

    html += '<p class="af-result-count">' + ligands.length + ' distinct ligands after collapsing '
      + intFmt(gene.nt) + ' transplants — ' + escape(summary)
      + '. Select one to focus it in the viewer and outline its contacting residues.</p>';

    html += '<div class="af-ligand-cards">'
      + ligands.map(ligandCardMarkup).join('')
      + '</div>';

    html += pocketTrack(gene, state.pockets || []);

    els.results.innerHTML = html;

    Array.prototype.forEach.call(els.results.querySelectorAll('[data-af-ccd-card]'), function (card) {
      card.addEventListener('click', function () {
        focusLigand(card.getAttribute('data-af-ccd-card'));
      });
    });

    bindViewer();
    openViewer(gene);
  }


  /* --------------------------------------------------------------------- *
   * Pocket track
   *
   * Pocket residues projected to genomic coordinates: residue p -> coding
   * nucleotides [(p-1)*3, (p-1)*3+2], walked across the CDS segments in
   * translation order, then merged into blocks. The projection is done at
   * build time in tools/alphafill_index.py, the same way Pfam envelopes are
   * projected, so a ligand-binding site is a genome interval like any other
   * and the question "does this variant sit in a predicted pocket?" becomes
   * answerable.
   * --------------------------------------------------------------------- */

  function pocketTrack(gene, pockets) {
    var withBlocks = pockets.filter(function (pocket) {
      return pocket.gb && pocket.gb.length;
    });
    if (!withBlocks.length) { return ''; }

    var lo = Infinity, hi = -Infinity;
    withBlocks.forEach(function (pocket) {
      pocket.gb.forEach(function (block) {
        if (block[0] < lo) { lo = block[0]; }
        if (block[1] > hi) { hi = block[1]; }
      });
    });
    var span = Math.max(hi - lo, 1);
    var width = 100;   /* percent; the SVG scales with its container */

    var rows = withBlocks.slice(0, 8).map(function (pocket, index) {
      var y = 8 + index * 20;
      var blocks = pocket.gb.map(function (block) {
        var x = ((block[0] - lo) / span) * width;
        var w = Math.max(((block[1] - block[0]) / span) * width, 0.4);
        return '<rect x="' + x.toFixed(3) + '%" y="' + y + '" width="' + w.toFixed(3)
          + '%" height="11" rx="2" fill="' + (pocket.cf ? '#285d46' : '#8a8f8b') + '">'
          + '<title>' + escape(pocket.p) + ' · ' + block[0].toLocaleString('en-US')
          + '–' + block[1].toLocaleString('en-US') + '</title></rect>';
      }).join('');
      return '<line x1="0" y1="' + (y + 5.5) + '" x2="100%" y2="' + (y + 5.5)
        + '" stroke="var(--mgdb-line)" stroke-width="1"/>' + blocks;
    }).join('');

    var height = 8 + Math.min(withBlocks.length, 8) * 20 + 4;
    var legend = withBlocks.map(function (pocket, index) {
      if (index >= 8) { return ''; }
      return '<li><span class="af-track-key" style="background:'
        + (pocket.cf ? '#285d46' : '#8a8f8b') + '"></span>'
        + escape(pocket.p) + ' — ' + pocket.res.length + ' residues'
        + (pocket.pr !== null && pocket.pr !== undefined
            ? ', p = ' + num(pocket.pr, 3) : '')
        + (pocket.cf ? ' <b>confident</b>' : '')
        + (pocket.lig ? ' · holds <b>' + escape(pocket.lig) + '</b> at '
            + num(pocket.d, 1) + ' Å' : '')
        + '</li>';
    }).join('');

    return '<section class="af-track" aria-labelledby="af-track-title">'
      + '<h3 id="af-track-title">Predicted pockets on the genome</h3>'
      + '<p class="mgdb-small">Pocket residues projected through the CDS of '
      + escape(gene.p) + ' onto ' + escape(gene.c) + ' '
      + lo.toLocaleString('en-US') + '–' + hi.toLocaleString('en-US')
      + '. A variant falling inside one of these blocks falls inside a predicted '
      + 'ligand-binding site.</p>'
      + '<svg class="af-track-svg" width="100%" height="' + height
      + '" role="img" aria-label="Predicted pocket residues along the gene">'
      + rows + '</svg>'
      + '<ul class="af-track-legend">' + legend + '</ul>'
      + '<p class="mgdb-small"><a href="/genomebrowser?loc=' + encodeURIComponent(gene.c)
      + '%3A' + lo + '..' + hi + '">Open this interval in the genome browser</a>'
      + ' · pocket predictions are P2Rank, computed independently of AlphaFill, so '
      + 'agreement between them is corroboration rather than restatement.</p>'
      + '</section>';
  }

  function loadGene(term) {
    term = String(term || '').trim();
    if (!term) { return; }
    closeSuggestions();
    els.results.innerHTML = '<div class="mgdb-loading" role="status">'
      + '<span class="mgdb-spinner" aria-hidden="true"></span> Looking up predicted ligands…</div>';
    teardownViewer();

    MGDB.request(API + '?action=gene&term=' + encodeURIComponent(term), { key: 'af-gene' })
      .then(function (data) {
        state.gene = data.gene || null;
        state.pockets = data.pockets || [];
        state.detail = null;
        state.activeCcd = null;
        renderGene(data);
        MGDB.announce('AlphaFill results for ' + term + ' loaded.');
      })
      .catch(function () {
        els.results.innerHTML = '<div class="mgdb-message mgdb-message-error">'
          + '<p>The AlphaFill index could not be reached. Try again in a moment.</p></div>';
      });
  }

  /* --------------------------------------------------------------------- *
   * The viewer
   * --------------------------------------------------------------------- */

  function viewerMarkup(gene) {
    if (!gene.m) {
      return '<div class="mgdb-message mgdb-message-info"><p>The AlphaFold model for '
        + escape(gene.p) + ' is not published here yet, so there is nothing to draw. Every metric '
        + 'below is unaffected.</p></div>';
    }
    return '<div class="af-viewer" data-af-viewer>'
      + '<div class="af-viewer-bar">'
      + '<label>Protein <select data-af-color>'
      + '<option value="plddt">pLDDT confidence</option>'
      + '<option value="ss">secondary structure</option>'
      + '<option value="spectrum">N to C</option>'
      + '<option value="plain">single colour</option>'
      + '</select></label>'
      + '<label>Ligands <select data-af-ligstyle>'
      + '<option value="stick">sticks + spheres</option>'
      + '<option value="sphere">spheres</option>'
      + '<option value="hide">hidden</option>'
      + '</select></label>'
      + '<button type="button" data-af-pocket class="is-on">Pocket residues</button>'
      + '<button type="button" data-af-reset>Reset view</button>'
      + '<button type="button" data-af-spin>Spin</button>'
      + '<a class="mgdb-button mgdb-button-quiet" href="' + escape(gene.m) + '" download>Model</a>'
      + (gene.lc ? '<a class="mgdb-button mgdb-button-quiet" href="' + escape(gene.lc)
                 + '" download>Ligands</a>' : '')
      + '</div>'
      + '<div class="af-viewport" data-af-viewport>'
      + '<div class="af-viewer-status" data-af-status>Loading the model…</div>'
      + '<div class="af-viewer-legend" data-af-legend></div>'
      + '</div>'
      + '<canvas class="af-strip" data-af-strip></canvas>'
      + '<p class="af-strip-meta" data-af-strip-meta></p>'
      + '</div>';
  }

  function viewerEl(selector) {
    var root = els.results.querySelector('[data-af-viewer]');
    return root ? root.querySelector(selector) : null;
  }

  function setStatus(message) {
    var node = viewerEl('[data-af-status]');
    if (node) { node.innerHTML = message; }
  }

  function teardownViewer() {
    if (state.viewer) {
      try { state.viewer.clear(); } catch (error) { /* the canvas is going away anyway */ }
    }
    state.viewer = null;
    state.ligModels = {};
    state.profile = null;
  }

  /* One CA atom per residue. AlphaFold writes per-residue pLDDT into the
     B-factor column, so the confidence profile is a property of the file and
     never has to be shipped beside it. */
  function residueProfile(model) {
    var atoms = model.selectedAtoms({ atom: 'CA' });
    return atoms.map(function (atom) {
      return { resi: atom.resi, value: atom.b, resn: atom.resn };
    }).sort(function (first, second) { return first.resi - second.resi; });
  }

  function drawStrip() {
    var canvas = viewerEl('[data-af-strip]');
    var meta = viewerEl('[data-af-strip-meta]');
    if (!canvas || !state.profile || !state.profile.length) { return; }

    var ratio = window.devicePixelRatio || 1;
    var width = canvas.clientWidth || 800;
    var height = canvas.clientHeight || 42;
    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(height * ratio);
    var context = canvas.getContext('2d');
    if (!context) { return; }
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);

    var profile = state.profile;
    var step = width / profile.length;
    var total = 0;
    var confident = 0;
    profile.forEach(function (residue, index) {
      context.fillStyle = hex(plddtColor(residue.value));
      context.fillRect(index * step, 0, Math.max(step, 1), height);
      total += residue.value;
      if (residue.value >= 70) { confident++; }
    });

    if (meta) {
      meta.innerHTML = 'Per-residue pLDDT across ' + profile.length + ' residues · mean <b>'
        + (total / profile.length).toFixed(1) + '</b> · <b>'
        + Math.round(100 * confident / profile.length) + '%</b> at 70 or above'
        + ' · read from the model’s B-factor column';
    }
  }

  function drawLegend() {
    var node = viewerEl('[data-af-legend]');
    if (!node) { return; }
    var scheme = viewerEl('[data-af-color]') ? viewerEl('[data-af-color]').value : 'plddt';
    var html = '';
    if (scheme === 'plddt') {
      html = '<b>Model pLDDT</b>'
        + legendRow(0x0053d6, 'very high &gt; 90')
        + legendRow(0x65cbf3, 'confident 70–90')
        + legendRow(0xffdb13, 'low 50–70')
        + legendRow(0xff7d45, 'very low &lt; 50');
    } else if (scheme === 'ss') {
      html = '<b>Secondary structure</b>'
        + legendRow(0xff0080, 'helix') + legendRow(0xffc800, 'sheet')
        + legendRow(0xffffff, 'coil');
    } else if (scheme === 'spectrum') {
      html = '<b>Chain direction</b><div class="af-legend-row">'
        + '<span class="af-legend-swatch" style="background:linear-gradient(90deg,#1f4fff,#00e5ff,'
        + '#7cff6b,#ffe066,#ff5252)"></span>N → C</div>';
    } else {
      html = '<b>Protein</b>' + legendRow(0x4dd0e1, 'single colour');
    }
    html += '<div style="margin-top:6px;border-top:1px solid #2b3644;padding-top:5px">'
      + '<b>Transplants</b><div class="af-legend-row">'
      + '<span class="af-legend-swatch" style="background:' + hex(LIGAND_PALETTE[0])
      + '"></span>one colour per ligand</div>'
      + '<div class="af-legend-row"><span class="af-legend-swatch" style="background:#8fd6ff">'
      + '</span>contacting residues</div></div>';
    node.innerHTML = html;
  }

  function legendRow(color, label) {
    return '<div class="af-legend-row"><span class="af-legend-swatch" style="background:'
      + hex(color) + '"></span>' + label + '</div>';
  }

  function applyProteinStyle() {
    if (!state.viewer) { return; }
    var select = viewerEl('[data-af-color]');
    var scheme = select ? select.value : 'plddt';
    var style;
    if (scheme === 'plddt') {
      style = { cartoon: { colorfunc: function (atom) { return plddtColor(atom.b); },
                           thickness: 0.4, arrows: true } };
    } else if (scheme === 'ss') {
      style = { cartoon: { colorscheme: 'ssJmol', thickness: 0.4, arrows: true } };
    } else if (scheme === 'spectrum') {
      style = { cartoon: { color: 'spectrum', thickness: 0.4, arrows: true } };
    } else {
      style = { cartoon: { color: '#4dd0e1', thickness: 0.4, arrows: true } };
    }
    state.viewer.setStyle({ model: 0 }, style);
    drawLegend();
  }

  function ligandStyle(index, focused) {
    var select = viewerEl('[data-af-ligstyle]');
    var mode = select ? select.value : 'stick';
    if (mode === 'hide') { return {}; }
    var color = hex(ligandColor(index));
    if (mode === 'sphere') {
      return { sphere: { color: color, radius: focused ? 1.0 : 0.75 } };
    }
    return { stick: { color: color, radius: focused ? 0.24 : 0.16 },
             sphere: { color: color, radius: focused ? 0.45 : 0.32 } };
  }

  /* Split the coordinates-only mmCIF into one 3Dmol model per transplant,
     keyed by label_asym_id. AlphaFill's metadata addresses transplants by that
     id, so this is what lets a card and a ligand find each other. */
  function splitLigandCif(text) {
    var lines = text.split('\n');
    var columns = [];
    var groups = {};
    var order = [];
    lines.forEach(function (line) {
      if (line.indexOf('_atom_site.') === 0) {
        columns.push(line.trim().split('.')[1]);
        return;
      }
      if (line.indexOf('HETATM') !== 0) { return; }
      var field = line.trim().split(/\s+/);
      var asymIndex = columns.indexOf('label_asym_id');
      if (asymIndex < 0 || asymIndex >= field.length) { return; }
      var asym = field[asymIndex];
      if (!groups[asym]) { groups[asym] = []; order.push(asym); }
      groups[asym].push(line);
    });

    var header = ['data_ligand', '#', 'loop_'].concat(columns.map(function (name) {
      return '_atom_site.' + name;
    }));
    var out = {};
    order.forEach(function (asym) {
      out[asym] = header.concat(groups[asym]).concat(['#']).join('\n') + '\n';
    });
    return out;
  }

  function drawLigands() {
    if (!state.viewer || !state.detail) { return; }
    var byCcd = {};
    (state.gene.lig || []).forEach(function (item, index) {
      byCcd[item.ccd] = index;
    });

    Object.keys(state.ligModels).forEach(function (asym) {
      var row = state.detail.byAsym[asym];
      var index = row ? (byCcd[row.ccd] === undefined ? 0 : byCcd[row.ccd]) : 0;
      var focused = !!(state.activeCcd && row && row.ccd === state.activeCcd);
      state.ligModels[asym].setStyle({}, ligandStyle(index, focused));
    });
    state.viewer.render();
  }

  function showPocket(residues) {
    if (!state.viewer) { return; }
    applyProteinStyle();
    var toggle = viewerEl('[data-af-pocket]');
    if (toggle && !toggle.classList.contains('is-on')) { return; }
    if (!residues || !residues.length) { return; }
    state.viewer.addStyle({ model: 0, resi: residues },
                          { stick: { radius: 0.11, colorscheme: 'cyanCarbon' } });
  }

  function focusLigand(ccd) {
    state.activeCcd = state.activeCcd === ccd ? null : ccd;

    Array.prototype.forEach.call(els.results.querySelectorAll('[data-af-ccd-card]'), function (card) {
      var on = card.getAttribute('data-af-ccd-card') === state.activeCcd;
      card.classList.toggle('is-active', on);
      card.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    if (!state.viewer || !state.detail) { return; }

    var residues = [];
    var target = null;
    if (state.activeCcd) {
      state.detail.rows.forEach(function (row) {
        if (row.ccd !== state.activeCcd) { return; }
        (row.res || []).forEach(function (value) {
          if (residues.indexOf(value) < 0) { residues.push(value); }
        });
        if (!target && state.ligModels[row.a]) { target = state.ligModels[row.a]; }
      });
    }

    showPocket(residues);
    drawLigands();
    if (target) {
      state.viewer.zoomTo({ model: target }, 400);
    } else {
      state.viewer.zoomTo({ model: 0 }, 400);
    }
    state.viewer.render();

    if (state.activeCcd) {
      var drawn = state.detail.rows.filter(function (row) {
        return row.ccd === state.activeCcd && row.nat;
      }).length;
      var missing = state.detail.rows.filter(function (row) {
        return row.ccd === state.activeCcd && !row.nat;
      }).length;
      setStatus('<b>' + escape(state.activeCcd) + '</b> · ' + drawn
        + (drawn === 1 ? ' copy' : ' copies') + ' drawn'
        + (missing ? ', ' + missing + ' with metadata but no coordinates' : '')
        + ' · ' + residues.length + ' contacting residues outlined');
    } else {
      setStatus(defaultStatus());
    }
  }

  function defaultStatus() {
    if (!state.detail) { return ''; }
    var drawn = Object.keys(state.ligModels).length;
    var missing = state.detail.rows.filter(function (row) { return !row.nat; }).length;
    return 'Drawing <b>' + drawn + '</b> transplanted ligand' + (drawn === 1 ? '' : 's')
      + (missing ? ' · ' + missing + ' listed with metadata but no coordinates' : '')
      + ' · select a ligand card to focus it';
  }

  function openViewer(gene) {
    if (!gene.m || !window.$3Dmol) { return; }
    var host = viewerEl('[data-af-viewport]');
    if (!host) { return; }

    state.viewer = window.$3Dmol.createViewer(host, {
      backgroundColor: '#05080b', antialias: true
    });

    /* Same problem, without a resize event to hang off: a tab that is restored
       rather than resized fires nothing. Watch the container instead, and stop
       once it has a width. */
    if (window.ResizeObserver && !host.clientWidth) {
      var observer = new window.ResizeObserver(function () {
        if (host.clientWidth && state.viewer) {
          state.viewer.resize();
          state.viewer.render();
          drawStrip();
          observer.disconnect();
        }
      });
      observer.observe(host);
    }

    window.fetch(gene.m, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) { throw new Error('model ' + response.status); }
        return response.text();
      })
      .then(function (text) {
        var model = state.viewer.addModel(text, 'pdb');
        state.profile = residueProfile(model);
        applyProteinStyle();
        state.viewer.zoomTo({ model: 0 });
        state.viewer.render();
        drawStrip();
        setStatus('Model loaded. Fetching transplanted ligands…');
        return loadTransplants(gene);
      })
      .catch(function () {
        setStatus('The structure could not be loaded. Every metric below is unaffected.');
      });
  }

  function loadTransplants(gene) {
    if (!gene.lc) {
      setStatus('Ligand coordinates for this protein are not published yet.');
      return null;
    }
    return Promise.all([
      MGDB.request(API + '?action=detail&term=' + encodeURIComponent(gene.p), { key: 'af-detail' }),
      window.fetch(gene.lc, { credentials: 'same-origin' }).then(function (response) {
        if (!response.ok) { throw new Error('ligands ' + response.status); }
        return response.text();
      })
    ]).then(function (results) {
      var rows = results[0].rows || [];
      var byAsym = {};
      rows.forEach(function (row) { byAsym[row.a] = row; });
      state.detail = { rows: rows, byAsym: byAsym };

      var blocks = splitLigandCif(results[1]);
      Object.keys(blocks).forEach(function (asym) {
        var model = state.viewer.addModel(blocks[asym], 'cif');
        state.ligModels[asym] = model;
      });

      drawLigands();
      state.viewer.zoomTo({ model: 0 });
      state.viewer.render();
      setStatus(defaultStatus());
    }).catch(function (error) {
      /* Name the failure. A viewer that says only "could not be loaded" is
         indistinguishable from a protein that genuinely has no coordinates,
         which is a real and common state here. */
      if (window.console && window.console.error) {
        window.console.error('AlphaFill: transplants failed to load', error);
      }
      setStatus('The transplanted ligands could not be drawn ('
        + escape(String((error && error.message) || error)) + '). '
        + 'The metrics below are unaffected.');
    });
  }

  function bindViewer() {
    var root = els.results.querySelector('[data-af-viewer]');
    if (!root) { return; }

    var color = root.querySelector('[data-af-color]');
    if (color) {
      color.addEventListener('change', function () {
        applyProteinStyle();
        if (state.activeCcd) { focusLigand(state.activeCcd); focusLigand(state.activeCcd); }
        state.viewer.render();
      });
    }
    var ligStyle = root.querySelector('[data-af-ligstyle]');
    if (ligStyle) { ligStyle.addEventListener('change', drawLigands); }

    var pocket = root.querySelector('[data-af-pocket]');
    if (pocket) {
      pocket.addEventListener('click', function () {
        pocket.classList.toggle('is-on');
        var active = state.activeCcd;
        state.activeCcd = null;
        if (active) { focusLigand(active); } else { applyProteinStyle(); state.viewer.render(); }
      });
    }
    var reset = root.querySelector('[data-af-reset]');
    if (reset) {
      reset.addEventListener('click', function () {
        state.activeCcd = null;
        focusLigand(null);
        state.viewer.zoomTo({ model: 0 });
        state.viewer.render();
      });
    }
    var spin = root.querySelector('[data-af-spin]');
    if (spin) {
      spin.addEventListener('click', function () {
        var on = spin.classList.toggle('is-on');
        state.viewer.spin(on ? 'y' : false);
      });
    }

    /* 3Dmol sizes its canvas once, from the container's width at creation. A
       page opened in a background tab, or in a pane that starts collapsed,
       creates the viewer at zero width and would stay blank forever without
       this -- the model is loaded and simply has nowhere to be drawn. */
    window.addEventListener('resize', MGDB.debounce(function () {
      if (state.viewer) {
        state.viewer.resize();
        state.viewer.render();
      }
      drawStrip();
    }, 200));
  }

  /* --------------------------------------------------------------------- *
   * Ligand view
   * --------------------------------------------------------------------- */

  function renderLigand(data) {
    if (!data.found) {
      els.ligandResults.innerHTML = '<div class="mgdb-empty"><p>'
        + escape(data.message || 'No maize protein received that ligand.') + '</p></div>';
      return;
    }
    var ligand = data.ligand;
    var counts = ligand.ev || {};
    var summary = EVIDENCE.filter(function (key) { return counts[key]; })
      .map(function (key) { return '<b>' + intFmt(counts[key]) + '</b> ' + EVIDENCE_LABEL[key]; })
      .join(' · ');

    var html = '<div class="af-ligand-summary">'
      + '<h3>' + escape(ligand.ccd) + '</h3>'
      + '<div class="af-identity-facts">'
      + '<span>' + escape((ligand.name || '').toLowerCase()) + '</span>'
      + (ligand.formula ? '<span class="af-ligand-formula">' + escape(ligand.formula) + '</span>' : '')
      + (ligand.mw ? '<span>' + num(ligand.mw, 1) + ' Da</span>' : '')
      + '<span><b>' + intFmt(ligand.ng) + '</b> genes</span>'
      + '<span>' + summary + '</span>'
      + '<span><a href="https://www.rcsb.org/ligand/' + encodeURIComponent(ligand.ccd)
      + '" target="_blank" rel="noopener">RCSB entry</a></span>'
      + '</div></div>';

    html += '<p class="af-result-count">Showing ' + intFmt(data.rows.length) + ' of '
      + intFmt(data.total) + ' genes, ranked by evidence then donor identity.</p>';

    html += '<div class="af-table-wrap"><table class="mgdb-table">'
      + '<caption class="mgdb-visually-hidden">Genes predicted to bind ' + escape(ligand.ccd)
      + '</caption><thead><tr>'
      + '<th scope="col">Gene</th><th scope="col">Evidence</th>'
      + '<th scope="col">Identity</th><th scope="col">Local RMSD</th>'
      + '<th scope="col">TCS</th><th scope="col">pLDDT</th>'
      + '<th scope="col">Pocket</th><th scope="col">Best donor</th></tr></thead><tbody>';

    data.rows.forEach(function (row) {
      html += '<tr><td>' + geneLink(row[0]) + '</td>'
        + '<td>' + badge(row[1]) + '</td>'
        + '<td class="af-num">' + (row[2] === null ? '—' : Math.round(row[2] * 100) + '%') + '</td>'
        + '<td class="af-num">' + num(row[3], 2) + '</td>'
        + '<td class="af-num">' + num(row[4], 2) + '</td>'
        + '<td class="af-num">' + num(row[5], 1) + '</td>'
        + '<td>' + (row[6] ? '<span class="af-badge af-badge-pocket">yes</span>' : '—') + '</td>'
        + '<td><a href="https://www.rcsb.org/structure/' + encodeURIComponent(row[7])
        + '" target="_blank" rel="noopener">' + escape(row[7]) + '</a></td></tr>';
    });
    html += '</tbody></table></div>';

    els.ligandResults.innerHTML = html;
  }

  function loadLigand(ccd) {
    ccd = String(ccd || '').trim().toUpperCase();
    if (!ccd) { return; }
    if (els.ligandInput) { els.ligandInput.value = ccd; }
    els.ligandResults.innerHTML = '<div class="mgdb-loading" role="status">'
      + '<span class="mgdb-spinner" aria-hidden="true"></span> Finding genes…</div>';
    var section = document.getElementById('af-ligand');
    if (section) { section.scrollIntoView({ block: 'start', behavior: 'smooth' }); }

    MGDB.request(API + '?action=ligand&term=' + encodeURIComponent(ccd) + '&limit=300',
                 { key: 'af-ligand' })
      .then(function (data) {
        renderLigand(data);
        MGDB.announce('Genes predicted to bind ' + ccd + ' loaded.');
      })
      .catch(function () {
        els.ligandResults.innerHTML = '<div class="mgdb-message mgdb-message-error">'
          + '<p>The ligand index could not be reached.</p></div>';
      });
  }

  /* --------------------------------------------------------------------- *
   * Browse
   *
   * The tier-1 index is 16,933 compact rows. Loading it once and filtering in
   * the browser is both faster and cheaper than a request per keystroke; the
   * whole file is smaller than one page of rendered table.
   * --------------------------------------------------------------------- */

  var IDX_GENE = 0, IDX_CHROM = 1, IDX_PLDDT = 2, IDX_LIGANDS = 3,
      IDX_STRONG = 4, IDX_BEST = 5, IDX_POCKET = 6;

  function renderBrowse() {
    if (!state.index) { return; }
    var chrom = els.browseChrom ? els.browseChrom.value : '';
    var sort = els.browseSort ? els.browseSort.value : 'strong';

    var rows = state.index.filter(function (row) {
      var best = EVIDENCE[row[IDX_BEST]];
      if (!state.browseFilters[best]) { return false; }
      if (chrom && row[IDX_CHROM] !== chrom) { return false; }
      if (state.browsePocketOnly && !row[IDX_POCKET]) { return false; }
      return true;
    });

    rows.sort(function (first, second) {
      if (sort === 'gene') { return first[IDX_GENE] < second[IDX_GENE] ? -1 : 1; }
      if (sort === 'plddt') { return second[IDX_PLDDT] - first[IDX_PLDDT]; }
      if (sort === 'ligands') { return second[IDX_LIGANDS] - first[IDX_LIGANDS]; }
      return (second[IDX_STRONG] - first[IDX_STRONG])
          || (second[IDX_LIGANDS] - first[IDX_LIGANDS]);
    });

    var shown = rows.slice(0, 300);
    var html = '<p class="af-result-count">' + intFmt(rows.length) + ' genes match'
      + (rows.length > shown.length ? ', showing the first ' + intFmt(shown.length) : '')
      + ' · of ' + intFmt(state.index.length) + ' with a transplant.</p>';

    if (!rows.length) {
      els.browseResults.innerHTML = html + '<div class="mgdb-empty"><p>No gene matches '
        + 'those filters. Ions and additives are off by default — turn one on to widen it.</p></div>';
      return;
    }

    html += '<div class="af-table-wrap"><table class="mgdb-table">'
      + '<caption class="mgdb-visually-hidden">Maize genes with at least one transplanted ligand'
      + '</caption><thead><tr>'
      + '<th scope="col">Gene</th><th scope="col">Chr</th><th scope="col">pLDDT</th>'
      + '<th scope="col">Ligands</th><th scope="col">Strong</th>'
      + '<th scope="col">Best evidence</th><th scope="col">Pockets</th></tr></thead><tbody>';
    shown.forEach(function (row) {
      html += '<tr><td>' + geneLink(row[IDX_GENE]) + '</td>'
        + '<td>' + escape(row[IDX_CHROM]) + '</td>'
        + '<td class="af-num">' + num(row[IDX_PLDDT], 1) + '</td>'
        + '<td class="af-num">' + row[IDX_LIGANDS] + '</td>'
        + '<td class="af-num">' + row[IDX_STRONG] + '</td>'
        + '<td>' + badge(EVIDENCE[row[IDX_BEST]]) + '</td>'
        + '<td class="af-num">' + (row[IDX_POCKET] || '—') + '</td></tr>';
    });
    html += '</tbody></table></div>';
    els.browseResults.innerHTML = html;
  }

  function loadIndex() {
    if (!els.browseResults) { return; }
    window.fetch(INDEX_URL, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) { throw new Error('index ' + response.status); }
        return response.json();
      })
      .then(function (rows) {
        state.index = rows;
        var chroms = {};
        rows.forEach(function (row) { chroms[row[IDX_CHROM]] = true; });
        if (els.browseChrom) {
          Object.keys(chroms).sort(chromSort).forEach(function (name) {
            var option = document.createElement('option');
            option.value = name;
            option.textContent = name;
            els.browseChrom.appendChild(option);
          });
        }
        if (els.targetsChrom) {
          Object.keys(chroms).sort(chromSort).forEach(function (name) {
            var option = document.createElement('option');
            option.value = name;
            option.textContent = name;
            els.targetsChrom.appendChild(option);
          });
        }
        renderBrowse();
      })
      .catch(function () {
        els.browseResults.innerHTML = '<div class="mgdb-message mgdb-message-error">'
          + '<p>The gene index could not be loaded.</p></div>';
      });
  }

  /* chr1..chr10 numerically, then scaffolds, so chr10 does not sort between
     chr1 and chr2. */
  function chromSort(first, second) {
    var a = /^chr(\d+)$/.exec(first);
    var b = /^chr(\d+)$/.exec(second);
    if (a && b) { return Number(a[1]) - Number(b[1]); }
    if (a) { return -1; }
    if (b) { return 1; }
    return first < second ? -1 : 1;
  }

  /* --------------------------------------------------------------------- *
   * Target list
   * --------------------------------------------------------------------- */

  function loadTargets() {
    var chrom = els.targetsChrom ? els.targetsChrom.value : '';
    els.targetsResults.innerHTML = '<div class="mgdb-loading" role="status">'
      + '<span class="mgdb-spinner" aria-hidden="true"></span> Loading the target list…</div>';
    MGDB.request(API + '?action=targets&limit=500'
                 + (chrom ? '&chrom=' + encodeURIComponent(chrom) : ''), { key: 'af-targets' })
      .then(function (data) {
        var html = '<p class="af-result-count">Showing ' + intFmt(data.rows.length) + ' of '
          + intFmt(data.total) + ' genes with a confident pocket and no qualifying donor.</p>'
          + '<div class="af-table-wrap"><table class="mgdb-table">'
          + '<caption class="mgdb-visually-hidden">Genes with a confident predicted pocket and no '
          + 'AlphaFill donor</caption><thead><tr>'
          + '<th scope="col">Gene</th><th scope="col">Model</th><th scope="col">Chr</th>'
          + '<th scope="col">pLDDT</th><th scope="col">Confident pockets</th>'
          + '<th scope="col">Best probability</th></tr></thead><tbody>';
        data.rows.forEach(function (row) {
          html += '<tr><td>' + geneLink(row[0]) + '</td>'
            + '<td class="af-table-gene">' + escape(row[1]) + '</td>'
            + '<td>' + escape(row[2]) + '</td>'
            + '<td class="af-num">' + num(row[3], 1) + '</td>'
            + '<td class="af-num">' + row[4] + '</td>'
            + '<td class="af-num">' + num(row[5], 3) + '</td></tr>';
        });
        html += '</tbody></table></div>';
        els.targetsResults.innerHTML = html;
        MGDB.announce('Target list loaded.');
      })
      .catch(function () {
        els.targetsResults.innerHTML = '<div class="mgdb-message mgdb-message-error">'
          + '<p>The target list could not be reached.</p></div>';
      });
  }

  /* --------------------------------------------------------------------- *
   * Dashboard charts
   * --------------------------------------------------------------------- */

  function renderCharts(stats) {
    if (!els.charts || !stats) { return; }
    var html = '';

    var bands = stats.hit_rate_by_plddt || [];
    if (bands.length) {
      html += '<div class="af-chart"><h3>Hit rate by model confidence</h3>';
      bands.forEach(function (band) {
        var pct = band.rate ? band.rate * 100 : 0;
        html += '<div class="af-bar-row"><span>' + escape(band.band) + '</span>'
          + '<span class="af-bar-track"><span class="af-bar-fill" style="width:'
          + pct.toFixed(1) + '%"></span></span>'
          + '<span class="af-bar-value">' + pct.toFixed(1) + '%</span></div>';
      });
      html += '<p class="mgdb-small">A sevenfold range. Yield tracks model quality because both '
        + 'pLDDT and length are proxies for whether a solved structure of a relative exists.</p></div>';
    }

    var evidence = stats.evidence || {};
    var total = EVIDENCE.reduce(function (sum, key) { return sum + (evidence[key] || 0); }, 0);
    if (total) {
      html += '<div class="af-chart"><h3>Gene &times; ligand pairs by evidence</h3>';
      EVIDENCE.forEach(function (key) {
        var value = evidence[key] || 0;
        html += '<div class="af-bar-row"><span>' + EVIDENCE_LABEL[key] + '</span>'
          + '<span class="af-bar-track"><span class="af-bar-fill" style="width:'
          + (100 * value / total).toFixed(1) + '%"></span></span>'
          + '<span class="af-bar-value">' + intFmt(value) + '</span></div>';
      });
      html += '<p class="mgdb-small">Ions and additives together are over half the rows, which is '
        + 'why the default view is the confident subset and why raw counts are a poor ranking.</p></div>';
    }

    els.charts.innerHTML = html;
  }

  /* --------------------------------------------------------------------- *
   * Init
   * --------------------------------------------------------------------- */

  function init() {
    els.searchForm = document.getElementById('af-search-form');
    if (!els.searchForm) { return; }

    els.searchInput = document.getElementById('af-search-input');
    els.suggestions = document.getElementById('af-suggestions');
    els.results = document.getElementById('af-results');
    els.ligandForm = document.getElementById('af-ligand-form');
    els.ligandInput = document.getElementById('af-ligand-input');
    els.ligandResults = document.getElementById('af-ligand-results');
    els.browseResults = document.getElementById('af-browse-results');
    els.browseChrom = document.getElementById('af-browse-chrom');
    els.browseSort = document.getElementById('af-browse-sort');
    els.targetsResults = document.getElementById('af-targets-results');
    els.targetsChrom = document.getElementById('af-targets-chrom');
    els.targetsLoad = document.getElementById('af-targets-load');
    els.charts = document.getElementById('af-charts');

    els.searchForm.addEventListener('submit', function (event) {
      event.preventDefault();
      loadGene(els.searchInput.value);
    });

    els.searchInput.addEventListener('input', MGDB.debounce(function () {
      var term = els.searchInput.value.trim();
      if (term.length < 2) { closeSuggestions(); return; }
      MGDB.request(API + '?action=suggest&term=' + encodeURIComponent(term), { key: 'af-suggest' })
        .then(renderSuggestions)
        .catch(closeSuggestions);
    }, 140));

    els.searchInput.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') { event.preventDefault(); moveSuggestion(1); }
      else if (event.key === 'ArrowUp') { event.preventDefault(); moveSuggestion(-1); }
      else if (event.key === 'Escape') { closeSuggestions(); }
    });

    document.addEventListener('click', function (event) {
      if (els.suggestions && !els.suggestions.hidden
          && !els.suggestions.contains(event.target) && event.target !== els.searchInput) {
        closeSuggestions();
      }
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-af-example]'), function (button) {
      button.addEventListener('click', function () {
        els.searchInput.value = button.getAttribute('data-af-example');
        loadGene(button.getAttribute('data-af-example'));
      });
    });

    if (els.ligandForm) {
      els.ligandForm.addEventListener('submit', function (event) {
        event.preventDefault();
        loadLigand(els.ligandInput.value);
      });
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-af-ligand]'), function (button) {
      button.addEventListener('click', function () {
        loadLigand(button.getAttribute('data-af-ligand'));
      });
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-af-filter]'), function (button) {
      button.addEventListener('click', function () {
        var key = button.getAttribute('data-af-filter');
        state.browseFilters[key] = !state.browseFilters[key];
        button.classList.toggle('is-on', state.browseFilters[key]);
        button.setAttribute('aria-pressed', state.browseFilters[key] ? 'true' : 'false');
        renderBrowse();
      });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-af-flag]'), function (button) {
      button.addEventListener('click', function () {
        state.browsePocketOnly = !state.browsePocketOnly;
        button.classList.toggle('is-on', state.browsePocketOnly);
        button.setAttribute('aria-pressed', state.browsePocketOnly ? 'true' : 'false');
        renderBrowse();
      });
    });
    if (els.browseChrom) { els.browseChrom.addEventListener('change', renderBrowse); }
    if (els.browseSort) { els.browseSort.addEventListener('change', renderBrowse); }
    if (els.targetsLoad) { els.targetsLoad.addEventListener('click', loadTargets); }
    if (els.targetsChrom) {
      els.targetsChrom.addEventListener('change', function () {
        if (els.targetsResults.innerHTML.trim()) { loadTargets(); }
      });
    }

    loadIndex();

    MGDB.request(API + '?action=stats', { key: 'af-stats' })
      .then(function (data) { renderCharts(data.stats); })
      .catch(function () { /* the metric grid above is server-rendered and stands alone */ });

    /* A gene or ligand can be linked to directly: the protein structure page
       and the gene record both do it. */
    var params = new window.URLSearchParams(window.location.search);
    var gene = params.get('gene') || params.get('id');
    var ligand = params.get('ligand');
    if (gene) {
      els.searchInput.value = gene;
      loadGene(gene);
    }
    if (ligand) { loadLigand(ligand); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
