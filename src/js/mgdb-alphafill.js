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
    domains: [],         /* canonical-protein InterPro/Pfam spans, loaded lazily */
    domainsLoaded: false,
    domainsError: false,
    activeCcd: null,
    activePocket: null,
    viewer: null,
    proteinModel: null,
    ligModels: {},       /* asym id -> 3Dmol model */
    profile: null,       /* per-residue pLDDT, from the B-factor column */
    chem: {},            /* CCD -> lazy RCSB chemical metadata promise */
    suggestVersion: 0,   /* prevents a late typeahead response reopening after submit */
    submittedTerm: '',   /* also suppresses a debounce queued before the submit click */
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
          + (gene.tp ? ' (best P2Rank probability ' + num(gene.tp, 3) + ')' : '')
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
        + (gene.m ? viewerMarkup(gene) : '')
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

    var donor = String(item.pdb || '').toUpperCase();
    return '<article class="af-ligand-card" data-af-ccd-card="' + escape(item.ccd) + '"'
      + ' data-af-evidence="' + escape(item.ev) + '">'
      + '<button class="af-ligand-focus" type="button" data-af-focus aria-pressed="false"'
      + ' aria-label="Focus ' + escape(item.ccd) + ' in the structure viewer">'
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
      + '</button>'
      + '<span class="af-ligand-links" data-af-chem-links="' + escape(item.ccd) + '">'
      + '<a href="https://www.rcsb.org/ligand/' + encodeURIComponent(item.ccd)
      + '" target="_blank" rel="noopener">' + escape(item.ccd) + ' ligand summary</a>'
      + (donor ? '<a href="https://www.rcsb.org/structure/' + encodeURIComponent(donor)
        + '" target="_blank" rel="noopener">Donor ' + escape(donor) + '</a>' : '')
      + '<span data-af-pubchem>PubChem ID loading&hellip;</span>'
      + '</span></article>';
  }

  function renderGene(data) {
    var gene = data.gene;
    if (!gene || data.state !== 'transplant') {
      els.results.innerHTML = stateMarkup(data);
      if (gene && data.state === 'no_donor' && gene.m) {
        bindPocketTrack();
        bindViewer();
        openViewer(gene);
      }
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
      var focus = card.querySelector('[data-af-focus]');
      if (!focus) { return; }
      focus.addEventListener('click', function () {
        focusLigand(card.getAttribute('data-af-ccd-card'));
        hydrateChemicalLinks(card.getAttribute('data-af-ccd-card'), card);
      });
    });

    bindPocketTrack();

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

  function pocketDownload(format) {
    var gene = state.gene || {};
    var pockets = (state.pockets || []).filter(function (pocket) {
      return pocket.gb && pocket.gb.length;
    });
    if (!gene.g || !gene.c || !pockets.length) { return; }

    var stem = String(gene.g + '-' + (gene.p || 'protein') + '-p2rank-pockets')
      .replace(/[^A-Za-z0-9_.-]/g, '_');
    var lines = [];
    if (format === 'bed') {
      lines.push('track name="' + gene.g + ' P2Rank pockets" description="P2Rank pocket residues projected through the CDS"');
      pockets.forEach(function (pocket) {
        var score = pocket.pr === null || pocket.pr === undefined
          ? 0 : Math.max(0, Math.min(1000, Math.round(Number(pocket.pr) * 1000)));
        pocket.gb.forEach(function (block, blockIndex) {
          /* BED is 0-based, half-open; the index stores 1-based inclusive GFF
             coordinates, so only the start needs subtracting. */
          lines.push([
            gene.c, Math.max(0, Number(block[0]) - 1), Number(block[1]),
            [gene.g, pocket.p, 'block' + (blockIndex + 1)].join('|'), score, '.'
          ].join('\t'));
        });
      });
      saveBlob(lines.join('\n') + '\n', 'text/plain;charset=utf-8', stem + '.bed');
      MGDB.announce('Genome-projected pockets downloaded as BED.');
      return;
    }

    lines.push([
      'gene', 'protein', 'chromosome', 'pocket', 'probability', 'confident',
      'residue_count', 'protein_residues', 'block_start_1based',
      'block_end_1based', 'coordinate_system'
    ].join('\t'));
    pockets.forEach(function (pocket) {
      pocket.gb.forEach(function (block) {
        lines.push([
          gene.g, gene.p || '', gene.c, pocket.p || '',
          pocket.pr === null || pocket.pr === undefined ? '' : pocket.pr,
          pocket.cf ? 'yes' : 'no', (pocket.res || []).length,
          (pocket.res || []).join(','), block[0], block[1], '1-based inclusive'
        ].join('\t'));
      });
    });
    saveBlob(lines.join('\n') + '\n', 'text/tab-separated-values;charset=utf-8', stem + '.tsv');
    MGDB.announce('Genome-projected pockets downloaded as TSV.');
  }

  function domainContext(gene, pockets, domains) {
    if (!state.domainsLoaded) {
      return '<div class="af-domain-context" data-af-domain-context>'
        + '<h4>Protein-domain context</h4>'
        + (state.domainsError
          ? '<p class="mgdb-small">Domain annotations could not be loaded. The pocket coordinates above are unaffected.</p>'
          : '<p class="mgdb-small"><span class="mgdb-spinner" aria-hidden="true"></span> Loading InterPro/Pfam domains&hellip;</p>')
        + '</div>';
    }

    var length = Number(gene.aa || 0);
    var valid = (domains || []).filter(function (domain) {
      return length > 0 && Number(domain.start) > 0 && Number(domain.end) >= Number(domain.start);
    }).sort(function (first, second) {
      return Number(first.start) - Number(second.start) || Number(first.end) - Number(second.end);
    });
    if (!valid.length) {
      return '<div class="af-domain-context" data-af-domain-context>'
        + '<h4>Protein-domain context</h4>'
        + '<p class="mgdb-small">No InterPro/Pfam domains are assigned to this protein isoform.</p></div>';
    }

    var colors = ['#2f6f55', '#4f7cac', '#8a5a9e', '#b46a3c', '#6b7b3f', '#287f88'];
    var laneEnds = [];
    valid.forEach(function (domain) {
      var lane = 0;
      while (lane < laneEnds.length && laneEnds[lane] >= Number(domain.start)) { lane++; }
      if (lane === laneEnds.length) { laneEnds.push(0); }
      laneEnds[lane] = Number(domain.end);
      domain._lane = lane;
    });
    var domainHeight = 10 + laneEnds.length * 22;
    var trackHeight = domainHeight + 22;
    var bars = valid.map(function (domain, index) {
      var x = Math.max(0, ((Number(domain.start) - 1) / length) * 100);
      var w = Math.max(((Number(domain.end) - Number(domain.start) + 1) / length) * 100, 0.6);
      var color = colors[index % colors.length];
      return '<rect x="' + x.toFixed(3) + '%" y="' + (6 + domain._lane * 22)
        + '" width="' + w.toFixed(3) + '%" height="15" rx="3" fill="' + color + '">'
        + '<title>' + escape(domain.accession || domain.name || 'Domain') + ' · residues '
        + Number(domain.start).toLocaleString('en-US') + '–' + Number(domain.end).toLocaleString('en-US')
        + '</title></rect>';
    }).join('');

    var seenResidues = {};
    (pockets || []).forEach(function (pocket) {
      (pocket.res || []).forEach(function (residue) { seenResidues[Number(residue)] = true; });
    });
    var pocketTicks = Object.keys(seenResidues).map(function (residue) {
      var x = Math.max(0, ((Number(residue) - 1) / length) * 100);
      return '<line x1="' + x.toFixed(3) + '%" y1="' + domainHeight + '" x2="'
        + x.toFixed(3) + '%" y2="' + (domainHeight + 12)
        + '" stroke="#b43f72" stroke-width="1.4"><title>P2Rank pocket residue '
        + escape(residue) + '</title></line>';
    }).join('');

    var legend = valid.map(function (domain, index) {
      var label = escape(domain.accession || domain.name || 'Domain');
      return '<li><span class="af-track-key" style="background:' + colors[index % colors.length] + '"></span>'
        + (domain.url ? '<a href="' + escape(domain.url) + '" target="_blank" rel="noopener">' + label + '</a>' : label)
        + ' <span>' + escape(domain.name || domain.description || '') + ' · '
        + Number(domain.start).toLocaleString('en-US') + '–'
        + Number(domain.end).toLocaleString('en-US') + '</span></li>';
    }).join('');

    return '<div class="af-domain-context" data-af-domain-context>'
      + '<h4>Protein-domain context</h4>'
      + '<p class="mgdb-small">InterPro/Pfam spans use protein residue coordinates; the magenta ticks are residues in P2Rank pockets. '
      + 'The chromosome track above shows the same pocket residues after CDS projection.</p>'
      + '<svg class="af-domain-svg" width="100%" height="' + trackHeight
      + '" role="img" aria-label="InterPro and Pfam domains with predicted pocket residues">'
      + bars + '<line x1="0" y1="' + (domainHeight + 6) + '" x2="100%" y2="'
      + (domainHeight + 6) + '" stroke="var(--mgdb-line)" stroke-width="1"/>' + pocketTicks + '</svg>'
      + '<div class="af-track-scale"><span>1</span><span>' + length.toLocaleString('en-US') + ' aa</span></div>'
      + '<ul class="af-domain-legend">' + legend + '</ul></div>';
  }

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
      return '<li><button class="af-pocket-link" type="button" data-af-pocket-focus="' + index
        + '"><span class="af-track-key" style="background:'
        + (pocket.cf ? '#285d46' : '#8a8f8b') + '"></span>'
        + escape(pocket.p) + ' — ' + pocket.res.length + ' residues'
        + (pocket.pr !== null && pocket.pr !== undefined
            ? ', probability = ' + num(pocket.pr, 3) : '')
        + (pocket.cf ? ' <b>confident</b>' : '')
        + (pocket.lig ? ' · holds <b>' + escape(pocket.lig) + '</b> at '
            + num(pocket.d, 1) + ' Å' : '')
        + '</button></li>';
    }).join('');

    return '<section class="af-track" data-af-pocket-track aria-labelledby="af-track-title">'
      + '<div class="af-track-head"><h3 id="af-track-title">Predicted pockets on the genome</h3>'
      + '<div class="af-track-actions" aria-label="Download genome-projected pocket coordinates">'
      + '<button class="mgdb-button mgdb-button-secondary" type="button" data-af-pocket-download="bed">Download BED</button>'
      + '<button class="mgdb-button mgdb-button-quiet" type="button" data-af-pocket-download="tsv">Download TSV</button>'
      + '</div></div>'
      + '<p class="mgdb-small">Pocket residues projected through the CDS of '
      + escape(gene.p) + ' onto ' + escape(gene.c) + ' '
      + lo.toLocaleString('en-US') + '–' + hi.toLocaleString('en-US')
      + '. A variant falling inside one of these blocks falls inside a predicted '
      + 'ligand-binding site. <b>Probability</b> is P2Rank’s 0–1 model score, not a statistical p-value.</p>'
      + '<svg class="af-track-svg" width="100%" height="' + height
      + '" role="img" aria-label="Predicted pocket residues along the gene">'
      + rows + '</svg>'
      + '<ul class="af-track-legend">' + legend + '</ul>'
      + domainContext(gene, pockets, state.domains || [])
      + '<p class="mgdb-small"><a href="/genomebrowser?loc=' + encodeURIComponent(gene.c)
      + '%3A' + lo + '..' + hi + '">Open this interval in the genome browser</a>'
      + ' · pocket predictions are P2Rank, computed independently of AlphaFill, so '
      + 'agreement between them is corroboration rather than restatement.</p>'
      + '</section>';
  }

  function bindPocketTrack() {
    Array.prototype.forEach.call(els.results.querySelectorAll('[data-af-pocket-focus]'), function (button) {
      button.addEventListener('click', function () {
        focusPredictedPocket(Number(button.getAttribute('data-af-pocket-focus')));
      });
    });
    Array.prototype.forEach.call(els.results.querySelectorAll('[data-af-pocket-download]'), function (button) {
      button.addEventListener('click', function () {
        pocketDownload(button.getAttribute('data-af-pocket-download'));
      });
    });
  }

  function refreshPocketTrack() {
    var current = els.results.querySelector('[data-af-pocket-track]');
    if (!current || !state.gene) { return; }
    var wrapper = document.createElement('div');
    wrapper.innerHTML = pocketTrack(state.gene, state.pockets || []);
    if (!wrapper.firstElementChild) { return; }
    current.replaceWith(wrapper.firstElementChild);
    bindPocketTrack();
  }

  function loadDomainContext(gene) {
    var hasProjection = (state.pockets || []).some(function (pocket) {
      return pocket.gb && pocket.gb.length;
    });
    if (!gene || !gene.g || !gene.p || !hasProjection) { return; }
    var requestedProtein = gene.p;
    MGDB.request(API + '?action=domains&term=' + encodeURIComponent(gene.g), { key: 'af-domains' })
      .then(function (data) {
        if (!state.gene || state.gene.p !== requestedProtein) { return; }
        state.domains = data.domains || [];
        state.domainsLoaded = true;
        state.domainsError = false;
        refreshPocketTrack();
      })
      .catch(function () {
        if (!state.gene || state.gene.p !== requestedProtein) { return; }
        state.domains = [];
        state.domainsLoaded = false;
        state.domainsError = true;
        refreshPocketTrack();
      });
  }

  function loadGene(term) {
    term = String(term || '').trim();
    if (!term) { return; }
    state.submittedTerm = term.toLowerCase();
    state.suggestVersion++;
    closeSuggestions();
    els.results.innerHTML = '<div class="mgdb-loading" role="status">'
      + '<span class="mgdb-spinner" aria-hidden="true"></span> Looking up predicted ligands…</div>';
    teardownViewer();

    MGDB.request(API + '?action=gene&term=' + encodeURIComponent(term), { key: 'af-gene' })
      .then(function (data) {
        state.gene = data.gene || null;
        state.pockets = data.pockets || [];
        state.domains = [];
        state.domainsLoaded = false;
        state.domainsError = false;
        state.detail = null;
        state.activeCcd = null;
        renderGene(data);
        loadDomainContext(state.gene);
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
    var pocketOptions = '<option value="">P2Rank pockets</option>';
    (state.pockets || []).forEach(function (pocket, index) {
      pocketOptions += '<option value="' + index + '">' + escape(pocket.p || ('Pocket ' + (index + 1)))
        + (pocket.pr !== null && pocket.pr !== undefined
            ? ' · probability ' + num(pocket.pr, 3) : '')
        + '</option>';
    });
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
      + '<label>Background <select data-af-background>'
      + '<option value="dark">dark</option><option value="white">white</option>'
      + '</select></label>'
      + ((state.pockets || []).length ? '<label class="af-p2rank-control">Pocket <select data-af-p2rank>'
        + pocketOptions + '</select></label>' : '')
      + '<button type="button" data-af-pocket class="is-on" aria-pressed="true"'
      + ' title="Show residues that contact transplanted ligands">Contact residues</button>'
      + '<button type="button" data-af-reset>Reset view</button>'
      + '<button type="button" data-af-spin>Spin</button>'
      + '<button type="button" data-af-fullscreen aria-pressed="false">Full screen</button>'
      + '<span class="af-viewer-downloads" aria-label="Download structure or image">'
      + '<button type="button" data-af-export="pdb">PDB</button>'
      + '<button type="button" data-af-export="cif">mmCIF</button>'
      + '<button type="button" data-af-export="png">PNG</button>'
      + '<button type="button" data-af-export="svg">SVG</button>'
      + '</span>'
      + '</div>'
      + '<div class="af-viewport" data-af-viewport>'
      + '<div class="af-viewer-status" data-af-status>Loading the model…</div>'
      + '<div class="af-viewer-legend" data-af-legend></div>'
      + '</div>'
      + '<canvas class="af-strip" data-af-strip></canvas>'
      + '<div class="af-strip-axis" data-af-strip-axis aria-hidden="true"></div>'
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
    state.proteinModel = null;
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
    var axis = viewerEl('[data-af-strip-axis]');
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
      var firstRes = profile[0].resi;
      var lastRes = profile[profile.length - 1].resi;
      meta.innerHTML = '<b>' + escape((state.gene && state.gene.p) || 'Protein') + '</b>'
        + ' · residues <b>' + firstRes + '–' + lastRes + '</b>'
        + ' · per-residue pLDDT across ' + profile.length + ' positions · mean <b>'
        + (total / profile.length).toFixed(1) + '</b> · <b>'
        + Math.round(100 * confident / profile.length) + '%</b> at 70 or above'
        + ' · read from the model’s B-factor column';
    }
    if (axis) {
      var ticks = [0, 0.25, 0.5, 0.75, 1];
      axis.innerHTML = ticks.map(function (fraction) {
        var index = Math.min(profile.length - 1, Math.round((profile.length - 1) * fraction));
        return '<span style="left:' + (fraction * 100).toFixed(1) + '%">'
          + profile[index].resi + '</span>';
      }).join('');
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

  function contactResidues() {
    if (!state.detail) { return []; }
    var residues = [];
    state.detail.rows.forEach(function (row) {
      if (state.activeCcd && row.ccd !== state.activeCcd) { return; }
      (row.res || []).forEach(function (value) {
        if (residues.indexOf(value) < 0) { residues.push(value); }
      });
    });
    return residues;
  }

  function applyViewerHighlights() {
    if (!state.viewer) { return; }
    applyProteinStyle();
    var toggle = viewerEl('[data-af-pocket]');
    var residues = contactResidues();
    if (toggle && toggle.classList.contains('is-on') && residues.length) {
      state.viewer.addStyle({ model: 0, resi: residues },
                            { stick: { radius: 0.11, colorscheme: 'cyanCarbon' } });
    }
    var pocket = state.activePocket !== null ? (state.pockets || [])[state.activePocket] : null;
    if (pocket && pocket.res && pocket.res.length) {
      state.viewer.addStyle({ model: 0, resi: pocket.res },
                            { stick: { radius: 0.14, colorscheme: 'magentaCarbon' },
                              sphere: { radius: 0.22, color: '#d63384' } });
    }
  }

  function focusPredictedPocket(index) {
    var pocket = (state.pockets || [])[index];
    if (!pocket || !state.viewer) { return; }
    state.activePocket = index;
    state.activeCcd = null;
    var select = viewerEl('[data-af-p2rank]');
    if (select) { select.value = String(index); }
    Array.prototype.forEach.call(els.results.querySelectorAll('[data-af-ccd-card]'), function (card) {
      card.classList.remove('is-active');
      var focus = card.querySelector('[data-af-focus]');
      if (focus) { focus.setAttribute('aria-pressed', 'false'); }
    });
    applyViewerHighlights();
    drawLigands();
    state.viewer.zoomTo({ model: 0, resi: pocket.res }, 500);
    state.viewer.render();
    setStatus('<b>' + escape(pocket.p || ('Pocket ' + (index + 1))) + '</b> · '
      + pocket.res.length + ' residues · P2Rank probability '
      + num(pocket.pr, 3) + ' · magenta highlights');
    var viewer = els.results.querySelector('[data-af-viewer]');
    if (viewer) { viewer.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }

  function focusLigand(ccd) {
    state.activeCcd = state.activeCcd === ccd ? null : ccd;

    state.activePocket = null;
    var p2rank = viewerEl('[data-af-p2rank]');
    if (p2rank) { p2rank.value = ''; }
    Array.prototype.forEach.call(els.results.querySelectorAll('[data-af-ccd-card]'), function (card) {
      var on = card.getAttribute('data-af-ccd-card') === state.activeCcd;
      card.classList.toggle('is-active', on);
      var focus = card.querySelector('[data-af-focus]');
      if (focus) { focus.setAttribute('aria-pressed', on ? 'true' : 'false'); }
    });

    if (!state.viewer || !state.detail) { return; }

    var target = null;
    if (state.activeCcd) {
      state.detail.rows.forEach(function (row) {
        if (row.ccd !== state.activeCcd) { return; }
        if (!target && state.ligModels[row.a]) { target = state.ligModels[row.a]; }
      });
    }

    var residues = contactResidues();
    applyViewerHighlights();
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

  function allStructureAtoms() {
    var atoms = state.proteinModel ? state.proteinModel.selectedAtoms({}).slice() : [];
    Object.keys(state.ligModels).forEach(function (key) {
      atoms = atoms.concat(state.ligModels[key].selectedAtoms({}));
    });
    return atoms;
  }

  function pdbText() {
    var atoms = allStructureAtoms();
    var lines = ['REMARK   Generated by the MaizeGDB AlphaFill viewer'];
    atoms.forEach(function (atom, index) {
      var record = atom.hetflag ? 'HETATM' : 'ATOM  ';
      var serial = String((index + 1) % 100000).padStart(5, ' ');
      var name = String(atom.atom || atom.elem || 'X').slice(0, 4).padStart(4, ' ');
      var resn = String(atom.resn || 'UNK').slice(0, 3).padStart(3, ' ');
      var chain = String(atom.chain || atom.chainid || 'A').slice(0, 1);
      var resi = String(atom.resi || 1).slice(-4).padStart(4, ' ');
      var xyz = [atom.x, atom.y, atom.z].map(function (value) {
        return Number(value || 0).toFixed(3).padStart(8, ' ');
      }).join('');
      var occupancy = Number(atom.occupancy === undefined ? 1 : atom.occupancy).toFixed(2).padStart(6, ' ');
      var b = Number(atom.b || 0).toFixed(2).padStart(6, ' ');
      var elem = String(atom.elem || '').slice(0, 2).padStart(2, ' ');
      lines.push(record + serial + ' ' + name + ' ' + resn + ' ' + chain + resi
        + '    ' + xyz + occupancy + b + '          ' + elem);
    });
    lines.push('END');
    return lines.join('\n') + '\n';
  }

  function cifToken(value) {
    value = String(value === undefined || value === null || value === '' ? '?' : value);
    return /^[A-Za-z0-9_.+\-]+$/.test(value) ? value : "'" + value.replace(/'/g, "''") + "'";
  }

  function cifText() {
    var lines = ['data_' + String((state.gene && state.gene.p) || 'alphafill').replace(/[^A-Za-z0-9_]/g, '_'),
      '#', 'loop_', '_atom_site.group_PDB', '_atom_site.id', '_atom_site.type_symbol',
      '_atom_site.label_atom_id', '_atom_site.label_comp_id', '_atom_site.label_asym_id',
      '_atom_site.label_seq_id', '_atom_site.Cartn_x', '_atom_site.Cartn_y', '_atom_site.Cartn_z',
      '_atom_site.occupancy', '_atom_site.B_iso_or_equiv'];
    allStructureAtoms().forEach(function (atom, index) {
      lines.push([
        atom.hetflag ? 'HETATM' : 'ATOM', index + 1, atom.elem || '?', atom.atom || '?',
        atom.resn || 'UNK', atom.chain || atom.chainid || 'A', atom.resi || 1,
        Number(atom.x || 0).toFixed(3), Number(atom.y || 0).toFixed(3),
        Number(atom.z || 0).toFixed(3),
        Number(atom.occupancy === undefined ? 1 : atom.occupancy).toFixed(2),
        Number(atom.b || 0).toFixed(2)
      ].map(cifToken).join(' '));
    });
    lines.push('#');
    return lines.join('\n') + '\n';
  }

  function saveBlob(content, type, filename) {
    var url = window.URL.createObjectURL(new window.Blob([content], { type: type }));
    var anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(function () { window.URL.revokeObjectURL(url); }, 1000);
  }

  function saveDataUri(uri, filename) {
    var anchor = document.createElement('a');
    anchor.href = uri;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
  }

  function exportViewer(format) {
    if (!state.viewer || !state.proteinModel) {
      setStatus('The model must finish loading before it can be exported.');
      return;
    }
    var stem = String((state.gene && state.gene.p) || 'maize-alphafill').replace(/[^A-Za-z0-9_.-]/g, '_');
    if (format === 'pdb') {
      saveBlob(pdbText(), 'chemical/x-pdb;charset=utf-8', stem + '-alphafill.pdb');
      return;
    }
    if (format === 'cif') {
      saveBlob(cifText(), 'chemical/x-mmcif;charset=utf-8', stem + '-alphafill.cif');
      return;
    }
    var png = state.viewer.pngURI();
    if (format === 'png') {
      saveDataUri(png, stem + '-alphafill.png');
      return;
    }
    var host = viewerEl('[data-af-viewport]');
    var width = Math.max(1, host ? host.clientWidth : 1200);
    var height = Math.max(1, host ? host.clientHeight : 800);
    var svg = '<?xml version="1.0" encoding="UTF-8"?>\n'
      + '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height
      + '" viewBox="0 0 ' + width + ' ' + height + '"><title>' + escape(stem)
      + ' AlphaFill structure</title><image width="100%" height="100%" href="' + png + '"/></svg>';
    saveBlob(svg, 'image/svg+xml;charset=utf-8', stem + '-alphafill.svg');
  }

  function hydrateChemicalLinks(ccd, root) {
    ccd = String(ccd || '').toUpperCase();
    if (!ccd || !root) { return; }
    if (!state.chem[ccd]) {
      state.chem[ccd] = window.fetch('https://data.rcsb.org/rest/v1/core/chemcomp/'
        + encodeURIComponent(ccd), { mode: 'cors' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .catch(function () { return null; });
    }
    state.chem[ccd].then(function (data) {
      var target = root.querySelector('[data-af-pubchem]');
      if (!target) { return; }
      var related = data && data.rcsb_chem_comp_related ? data.rcsb_chem_comp_related : [];
      var pubchem = related.find(function (item) { return item.resource_name === 'PubChem'; });
      if (pubchem && pubchem.resource_accession_code) {
        target.innerHTML = '<a href="https://pubchem.ncbi.nlm.nih.gov/compound/'
          + encodeURIComponent(pubchem.resource_accession_code) + '" target="_blank" rel="noopener">'
          + 'PubChem CID ' + escape(pubchem.resource_accession_code) + '</a>';
      } else {
        target.textContent = 'No PubChem cross-reference';
      }
    });
  }

  function setViewerBackground(value) {
    var root = els.results.querySelector('[data-af-viewer]');
    var light = value === 'white';
    if (root) { root.classList.toggle('is-light', light); }
    if (state.viewer) {
      state.viewer.setBackgroundColor(light ? '#ffffff' : '#05080b');
      state.viewer.render();
    }
  }

  function syncFullscreenButton() {
    var root = els.results.querySelector('[data-af-viewer]');
    var button = viewerEl('[data-af-fullscreen]');
    if (!root || !button) { return; }
    var on = document.fullscreenElement === root || document.webkitFullscreenElement === root;
    button.textContent = on ? 'Exit full screen' : 'Full screen';
    button.setAttribute('aria-pressed', on ? 'true' : 'false');
    window.setTimeout(function () {
      if (state.viewer) { state.viewer.resize(); state.viewer.render(); drawStrip(); }
    }, 80);
  }

  function toggleFullscreen() {
    var root = els.results.querySelector('[data-af-viewer]');
    if (!root) { return; }
    if (document.fullscreenElement || document.webkitFullscreenElement) {
      var exit = document.exitFullscreen || document.webkitExitFullscreen;
      if (exit) { exit.call(document); }
      return;
    }
    var request = root.requestFullscreen || root.webkitRequestFullscreen;
    if (request) { request.call(root); }
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
        state.proteinModel = model;
        state.profile = residueProfile(model);
        applyViewerHighlights();
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
      applyViewerHighlights();
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
        applyViewerHighlights();
        state.viewer.render();
      });
    }
    var ligStyle = root.querySelector('[data-af-ligstyle]');
    if (ligStyle) { ligStyle.addEventListener('change', drawLigands); }

    var pocket = root.querySelector('[data-af-pocket]');
    if (pocket) {
      pocket.addEventListener('click', function () {
        var on = pocket.classList.toggle('is-on');
        pocket.setAttribute('aria-pressed', on ? 'true' : 'false');
        applyViewerHighlights();
        state.viewer.render();
        var count = contactResidues().length;
        setStatus(on
          ? '<b>Contact residues shown.</b> ' + count + ' residues are outlined in cyan.'
          : '<b>Contact residues hidden.</b> Ligands and P2Rank pockets are unchanged.');
      });
    }
    var p2rank = root.querySelector('[data-af-p2rank]');
    if (p2rank) {
      p2rank.addEventListener('change', function () {
        if (p2rank.value === '') {
          state.activePocket = null;
          applyViewerHighlights();
          state.viewer.zoomTo({ model: 0 }, 400);
          state.viewer.render();
          setStatus(defaultStatus() || 'P2Rank pocket highlight cleared.');
          return;
        }
        focusPredictedPocket(Number(p2rank.value));
      });
    }
    var background = root.querySelector('[data-af-background]');
    if (background) {
      background.addEventListener('change', function () { setViewerBackground(background.value); });
    }
    var reset = root.querySelector('[data-af-reset]');
    if (reset) {
      reset.addEventListener('click', function () {
        state.activeCcd = null;
        state.activePocket = null;
        if (p2rank) { p2rank.value = ''; }
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
    var fullscreen = root.querySelector('[data-af-fullscreen]');
    if (fullscreen) { fullscreen.addEventListener('click', toggleFullscreen); }
    Array.prototype.forEach.call(root.querySelectorAll('[data-af-export]'), function (button) {
      button.addEventListener('click', function () {
        exportViewer(button.getAttribute('data-af-export'));
      });
    });
    document.addEventListener('fullscreenchange', syncFullscreenButton);
    document.addEventListener('webkitfullscreenchange', syncFullscreenButton);

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
      + '<span data-af-pubchem>PubChem ID loading&hellip;</span>'
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
    hydrateChemicalLinks(ligand.ccd, els.ligandResults.querySelector('.af-ligand-summary'));
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
          + '<th scope="col">Best P2Rank probability</th></tr></thead><tbody>';
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

  function bindSectionTabs() {
    var nav = document.querySelector('.mgdb-alphafill-page .mgdb-section-tabs');
    if (!nav) { return; }
    var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
    if (!links.length) { return; }

    function setCurrent(link) {
      links.forEach(function (item) {
        var on = item === link;
        item.classList.toggle('is-current', on);
        if (on) { item.setAttribute('aria-current', 'location'); }
        else { item.removeAttribute('aria-current'); }
      });
    }

    links.forEach(function (link) {
      link.addEventListener('click', function () { setCurrent(link); });
    });

    var hashLink = links.find(function (link) {
      return link.getAttribute('href') === window.location.hash;
    });
    if (hashLink) { setCurrent(hashLink); }

    if (!window.IntersectionObserver) { return; }
    var byId = {};
    links.forEach(function (link) {
      var target = document.getElementById(link.getAttribute('href').slice(1));
      if (target) { byId[target.id] = link; }
    });
    var visible = {};
    var observer = new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        visible[entry.target.id] = entry.isIntersecting ? entry.boundingClientRect.top : null;
      });
      var candidates = Object.keys(visible).filter(function (id) { return visible[id] !== null; });
      if (!candidates.length) { return; }
      candidates.sort(function (first, second) {
        return Math.abs(visible[first]) - Math.abs(visible[second]);
      });
      if (byId[candidates[0]]) { setCurrent(byId[candidates[0]]); }
    }, { rootMargin: '-96px 0px -58% 0px', threshold: [0, 0.05] });
    Object.keys(byId).forEach(function (id) { observer.observe(document.getElementById(id)); });
  }

  function init() {
    bindSectionTabs();
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
      if (term.toLowerCase() === state.submittedTerm) { closeSuggestions(); return; }
      var requestVersion = ++state.suggestVersion;
      MGDB.request(API + '?action=suggest&term=' + encodeURIComponent(term), { key: 'af-suggest' })
        .then(function (payload) {
          if (requestVersion === state.suggestVersion) { renderSuggestions(payload); }
        })
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

    if (els.browseResults) { loadIndex(); }

    if (els.charts) {
      MGDB.request(API + '?action=stats', { key: 'af-stats' })
        .then(function (data) { renderCharts(data.stats); })
        .catch(function () { /* the server-rendered corpus summary stands alone */ });
    }

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
