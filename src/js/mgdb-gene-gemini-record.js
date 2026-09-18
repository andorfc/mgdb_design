/* ==========================================================================
   Gene Record Gemini Mockup — /gene_center/gene_gemini/{id}
   --------------------------------------------------------------------------
   Four-view layout:
     1. Visual overview (interactive 3D, structure, plots, metrics)
     2. Gene model (classic order, modern tables & lists only)
     3. Pan-gene (presence matrix + cross-assembly member & synteny tables)
     4. Genetic information (classic locus data, multi-locus support)
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  var R = window.MGDBRecord;
  if (!MGDB || !R) { return; }

  var els = {};
  var currentPayload = null;
  var activeView = 'visual';
  var activeLocusIndex = 0;

  function num(val) {
    return (val === null || val === undefined) ? '' : R.number(val);
  }

  function esc(val) {
    return R.escape(val);
  }

  function safe(val, fallback) {
    return (val !== null && val !== undefined && val !== '') ? esc(val) : (fallback || '<span class="mgdb-muted">Not recorded</span>');
  }

  /* ------------------------------------------------------------------------
     Initialize Elements
     ------------------------------------------------------------------------ */
  function initElements() {
    els.main = document.getElementById('gene-record-top');
    els.loading = document.getElementById('gene-record-loading');
    els.error = document.getElementById('gene-record-error');
    els.notice = document.getElementById('gene-record-notice');
    els.retry = document.getElementById('gene-record-retry');
    els.report = document.getElementById('gene-record-report');
    els.versionNotice = document.getElementById('gene-record-version-notice');
    els.ideogram = document.getElementById('gene-record-ideogram');
    els.glance = document.getElementById('gene-record-glance');
    els.functionLine = document.getElementById('gene-record-function-line');
    els.synonyms = document.getElementById('gene-record-synonyms');
    els.subtitle = document.getElementById('gene-record-subtitle');

    // View panels
    els.panels = {
      visual: document.getElementById('view-visual'),
      genemodel: document.getElementById('view-genemodel'),
      pangenome: document.getElementById('view-pangenome'),
      locus: document.getElementById('view-locus')
    };

    // Subnav bars
    els.subnavs = {
      visual: document.getElementById('gemini-subnav-visual'),
      genemodel: document.getElementById('gemini-subnav-genemodel'),
      pangenome: document.getElementById('gemini-subnav-pangenome'),
      locus: document.getElementById('gemini-subnav-locus')
    };

    // Tab buttons
    els.tabButtons = {
      visual: document.getElementById('btn-view-visual'),
      genemodel: document.getElementById('btn-view-genemodel'),
      pangenome: document.getElementById('btn-view-pangenome'),
      locus: document.getElementById('btn-view-locus')
    };

    els.multiLocusSwitch = document.getElementById('gemini-multi-locus-switch');
    els.locusTabLabel = document.getElementById('gemini-locus-tab-label');
    els.locusContentArea = document.getElementById('gemini-locus-content-area');

    // Image lightbox
    els.dialog = document.getElementById('gene-record-image-dialog');
    if (els.dialog) {
      els.lightboxClose = els.dialog.querySelector('.mgdb-rec-lightbox-close');
      els.lightboxImg = els.dialog.querySelector('.mgdb-rec-lightbox-image img');
      els.lightboxTitle = document.getElementById('gene-lightbox-title');
      els.lightboxBadge = els.dialog.querySelector('.mgdb-rec-image-badge');
      els.lightboxCaption = els.dialog.querySelector('.mgdb-rec-lightbox-caption');
      els.lightboxRecord = els.dialog.querySelector('[data-role="record"]');
      els.lightboxFile = els.dialog.querySelector('[data-role="file"]');
      els.lightboxCopy = els.dialog.querySelector('[data-role="copy"]');

      if (els.lightboxClose) {
        els.lightboxClose.addEventListener('click', function () {
          els.dialog.close();
        });
      }
      els.dialog.addEventListener('click', function (e) {
        if (e.target === els.dialog) { els.dialog.close(); }
      });
    }

    // Attach view tab button click listeners
    Object.keys(els.tabButtons).forEach(function (viewName) {
      var btn = els.tabButtons[viewName];
      if (btn) {
        btn.addEventListener('click', function () {
          switchView(viewName);
        });
      }
    });

    if (els.retry) {
      els.retry.addEventListener('click', loadRecord);
    }
  }

  /* ------------------------------------------------------------------------
     View Switching & Navigation
     ------------------------------------------------------------------------ */
  function switchView(viewName, targetAnchor) {
    if (!els.panels[viewName]) { return; }
    activeView = viewName;

    // Update tab button states
    Object.keys(els.tabButtons).forEach(function (k) {
      var btn = els.tabButtons[k];
      if (!btn) { return; }
      var isActive = (k === viewName);
      btn.classList.toggle('is-active', isActive);
      btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    // Update panel visibility
    Object.keys(els.panels).forEach(function (k) {
      var p = els.panels[k];
      if (p) {
        p.hidden = (k !== viewName);
      }
    });

    // Update sticky subnav visibility
    Object.keys(els.subnavs).forEach(function (k) {
      var nav = els.subnavs[k];
      if (nav) {
        nav.hidden = (k !== viewName);
      }
    });

    // Scroll to anchor if requested, otherwise maintain position or scroll to top of view
    if (targetAnchor) {
      var targetEl = document.getElementById(targetAnchor);
      if (targetEl) {
        targetEl.scrollIntoView({ behavior: 'smooth' });
      }
    }
  }

  /* ------------------------------------------------------------------------
     Hero Ideogram & Karyotype
     ------------------------------------------------------------------------ */
  function renderIdeogram(overview) {
    if (!els.ideogram || !els.main) { return; }
    var chrName = els.main.dataset.chr || overview.chromosome;
    var chrLen = parseInt(els.main.dataset.chrLength, 10);
    var karyotypeRaw = els.main.dataset.karyotype;
    var karyotype = [];

    try {
      if (karyotypeRaw) { karyotype = JSON.parse(karyotypeRaw); }
    } catch (e) { karyotype = []; }

    if (!chrName || !karyotype.length) {
      els.ideogram.hidden = true;
      return;
    }

    var maxLen = 0;
    karyotype.forEach(function (k) {
      if (k[1] > maxLen) { maxLen = k[1]; }
    });
    if (maxLen <= 0) { maxLen = 308452471; }

    var pos = overview.start || 0;
    var posPct = chrLen > 0 ? Math.min(100, Math.max(0, (pos / chrLen) * 100)) : 50;

    var html = '<h3>Genomic Location</h3><ul class="gemini-karyotype">';
    karyotype.forEach(function (k) {
      var cName = k[0];
      var cLen = k[1];
      var isHere = (cName.toLowerCase() === chrName.toLowerCase() ||
                    cName.toLowerCase() === 'chr' + chrName.toLowerCase().replace('chr', ''));
      var heightPct = Math.round((cLen / maxLen) * 100);

      html += '<li class="gemini-chr' + (isHere ? ' is-here' : '') + '">';
      html += '<div class="gemini-chr-bar" style="height:' + heightPct + '%;">';
      if (isHere) {
        html += '<div class="gemini-chr-pin" style="bottom:' + Math.round(posPct) + '%;"></div>';
      }
      html += '</div><span class="gemini-chr-label">' + cName.replace(/^[Cc]hr/, '') + '</span></li>';
    });
    html += '</ul>';

    html += '<div class="gemini-chrline"><div class="gemini-chrline-pin" style="left:' + posPct.toFixed(1) + '%;"></div></div>';
    html += '<div class="gemini-chrline-ends"><span>1 bp</span><span>' + esc(chrName) + ' (' + (chrLen ? num(chrLen) + ' bp' : '') + ')</span><span>' + (chrLen ? num(chrLen) : '') + ' bp</span></div>';
    html += '<p class="gemini-ideogram-caption">Pinned at <strong>' + esc(chrName) + ':' + num(overview.start) + '&ndash;' + num(overview.end) + '</strong> (' + num(overview.span_bp || (overview.end - overview.start)) + ' bp)</p>';

    els.ideogram.innerHTML = html;
    els.ideogram.hidden = false;
  }

  /* ------------------------------------------------------------------------
     Hero Glance Tiles
     ------------------------------------------------------------------------ */
  function renderGlance(data, sections) {
    if (!els.glance) { return; }
    var overview = sections.overview || {};
    var structure = sections.structure || {};
    var fn = sections.function || {};
    var variation = sections.variation || {};
    var pan = sections.pan_gene || {};
    var locus = sections.locus || {};
    var refs = sections.references || {};

    var tiles = [];

    // Transcripts
    var tCount = overview.transcript_count || (structure.transcripts ? structure.transcripts.length : 1);
    tiles.push({
      count: tCount,
      label: 'Transcripts',
      sub: tCount > 1 ? 'isoforms' : 'single isoform',
      color: 'var(--mgdb-green)',
      view: 'visual',
      anchor: 'gemini-visual-structure'
    });

    // GO Annotations
    var goCount = fn.go ? fn.go.length : 0;
    tiles.push({
      count: goCount,
      label: 'GO Terms',
      sub: 'functional terms',
      color: 'var(--mgdb-status-info)',
      view: 'visual',
      anchor: 'gemini-visual-function'
    });

    // Protein Domains
    var domCount = structure.domains && structure.domains.domains ? structure.domains.domains.length : (structure.protein_domains ? structure.protein_domains.length : 0);
    tiles.push({
      count: domCount,
      label: 'Domains',
      sub: 'InterPro signatures',
      color: 'var(--mgdb-leaf)',
      view: 'visual',
      anchor: 'gemini-visual-structure'
    });

    // Insertions
    var insCount = variation.insertions ? variation.insertions.length : 0;
    tiles.push({
      count: insCount,
      label: 'Insertions',
      sub: 'transposon stocks',
      color: 'var(--mgdb-gold)',
      view: 'genemodel',
      anchor: 'gemini-gm-insertions'
    });

    // Pan-gene Genomes
    var panCount = pan.assembly_count || (pan.members ? pan.members.length : 0);
    if (panCount > 0) {
      tiles.push({
        count: panCount,
        label: 'Pan-Genomes',
        sub: pan.pan_gene && pan.pan_gene.class ? pan.pan_gene.class : 'NAM assemblies',
        color: 'var(--mgdb-orange)',
        view: 'pangenome',
        anchor: 'gemini-pg-members'
      });
    }

    // Classical Alleles
    var alleleCount = (variation.alleles ? variation.alleles.length : 0) || (locus.alleles ? locus.alleles.length : 0);
    if (alleleCount > 0) {
      tiles.push({
        count: alleleCount,
        label: 'Alleles',
        sub: 'curated variants',
        color: 'var(--mgdb-burgundy)',
        view: 'locus',
        anchor: 'gemini-locus-alleles'
      });
    }

    // References
    var refCount = refs.references ? refs.references.length : 0;
    tiles.push({
      count: refCount,
      label: 'Publications',
      sub: 'literature citations',
      color: 'var(--mgdb-wine)',
      view: 'visual',
      anchor: 'gemini-visual-references'
    });

    var html = '';
    tiles.forEach(function (t) {
      html += '<button type="button" class="gemini-glance-tile" style="--tile-color:' + t.color + ';" data-view="' + t.view + '" data-anchor="' + t.anchor + '">';
      html += '<strong>' + num(t.count) + '</strong>';
      html += '<span>' + esc(t.label) + '</span>';
      html += '<small>' + esc(t.sub) + '</small>';
      html += '</button>';
    });

    els.glance.innerHTML = html;
    els.glance.hidden = false;

    // Click handler for glance tiles
    var tileButtons = els.glance.querySelectorAll('.gemini-glance-tile');
    for (var i = 0; i < tileButtons.length; i++) {
      tileButtons[i].addEventListener('click', function () {
        var v = this.getAttribute('data-view');
        var a = this.getAttribute('data-anchor');
        switchView(v, a);
      });
    }
  }

  /* ------------------------------------------------------------------------
     VIEW 1: Visual Overview Renderers
     ------------------------------------------------------------------------ */
  function renderVisualOverview(sections, data) {
    var overview = sections.overview || {};
    var structure = sections.structure || {};
    var fn = sections.function || {};
    var expr = sections.expression || {};
    var variation = sections.variation || {};
    var orthologs = sections.orthologs || {};
    var locus = sections.locus || {};
    var refs = sections.references || {};
    var seqs = sections.sequences || {};

    // 1. Overview Section Body
    var ovBody = document.getElementById('gemini-visual-overview-body');
    if (ovBody) {
      var ovHtml = '<div class="gemini-dl-grid">';
      ovHtml += '<div class="gemini-dl-item"><dt>Gene Model ID</dt><dd class="mgdb-record-id">' + esc(overview.name || data.id) + '</dd></div>';
      if (overview.symbol) {
        ovHtml += '<div class="gemini-dl-item"><dt>Gene Symbol</dt><dd><strong>' + esc(overview.symbol) + '</strong></dd></div>';
      }
      if (overview.full_name) {
        ovHtml += '<div class="gemini-dl-item"><dt>Full Name</dt><dd>' + esc(overview.full_name) + '</dd></div>';
      }
      ovHtml += '<div class="gemini-dl-item"><dt>Genomic Location</dt><dd>' + esc(overview.chromosome) + ':' + num(overview.start) + '&ndash;' + num(overview.end) + ' (' + esc(overview.strand || '+') + ' strand)</dd></div>';
      ovHtml += '<div class="gemini-dl-item"><dt>Assembly &amp; Annotation</dt><dd>' + esc(overview.assembly || '') + ' ' + (overview.annotation ? '(' + esc(overview.annotation) + ')' : '') + '</dd></div>';
      ovHtml += '<div class="gemini-dl-item"><dt>Model Type</dt><dd>' + esc(overview.model_type || 'protein_coding') + '</dd></div>';
      ovHtml += '<div class="gemini-dl-item"><dt>Canonical Transcript</dt><dd>' + esc(overview.canonical_transcript || 'None') + '</dd></div>';
      ovHtml += '<div class="gemini-dl-item"><dt>Canonical Protein</dt><dd>' + esc(overview.canonical_protein || 'None') + '</dd></div>';
      ovHtml += '</div>';

      if (fn.summary) {
        ovHtml += '<div class="mgdb-message mgdb-message-info" style="margin-top:var(--mgdb-space-3);"><div><strong>Summary: </strong><span>' + esc(fn.summary) + '</span></div></div>';
      }
      ovBody.innerHTML = ovHtml;
    }

    // 2. Gene & Protein Structure (AlphaFold 3D + Domain track)
    var structBody = document.getElementById('gemini-visual-structure-body');
    if (structBody && MGDB.geneStructure) {
      structBody.innerHTML = '';
      try {
        MGDB.geneStructure(structBody, {
          gene: {
            name: overview.name || data.id,
            symbol: overview.symbol || '',
            chromosome: overview.chromosome || '',
            strand: overview.strand || '+',
            start: overview.start,
            end: overview.end
          },
          geneModel: structure.gene_model || null,
          domains: structure.domains || null,
          model: structure.model || null,
          base: ''
        });
      } catch (e) {
        structBody.innerHTML = '<p class="mgdb-muted">Structure preview not available for this record.</p>';
      }
    }

    // 3. Function (GO & Pathways)
    var fnBody = document.getElementById('gemini-visual-function-body');
    if (fnBody && MGDB.geneFunction) {
      fnBody.innerHTML = '';
      try {
        MGDB.geneFunction(fnBody, {
          gene: { name: overview.name || data.id, symbol: overview.symbol || '' },
          fn: fn,
          base: ''
        });
      } catch (e) {
        fnBody.innerHTML = '<p class="mgdb-muted">Function visualizer not available for this record.</p>';
      }
    }

    // 4. Expression (qTeller & Plots)
    var exprBody = document.getElementById('gemini-visual-expression-body');
    if (exprBody && MGDB.geneExpression) {
      exprBody.innerHTML = '';
      try {
        MGDB.geneExpression(exprBody, {
          gene: { name: overview.name || data.id, symbol: overview.symbol || '' },
          profile: expr.profile || null,
          qteller: expr.qteller || null
        });
      } catch (e) {
        exprBody.innerHTML = '<p class="mgdb-muted">Expression visualizer not available for this record.</p>';
      }
    }

    // 5. Variation Summary
    var varBody = document.getElementById('gemini-visual-variation-body');
    if (varBody) {
      var insList = variation.insertions || [];
      var alleleList = variation.alleles || (locus.alleles || []);
      var snpList = variation.snp_traits || [];

      var varHtml = '<div class="gemini-dl-grid">';
      varHtml += '<div class="gemini-dl-item"><dt>Transposon Insertions</dt><dd><strong>' + num(insList.length) + '</strong> curated insertion stocks</dd></div>';
      varHtml += '<div class="gemini-dl-item"><dt>Curated Alleles</dt><dd><strong>' + num(alleleList.length) + '</strong> alleles documented</dd></div>';
      varHtml += '<div class="gemini-dl-item"><dt>SNP / Trait Associations</dt><dd><strong>' + num(snpList.length) + '</strong> GWAS hits</dd></div>';
      varHtml += '</div>';

      if (insList.length > 0) {
        varHtml += '<h4 style="margin:var(--mgdb-space-4) 0 var(--mgdb-space-2);">Featured Insertions</h4>';
        varHtml += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Stock</th><th>Type</th><th>Position</th><th>Gene Feature</th><th>Stock Availability</th></tr></thead><tbody>';
        insList.slice(0, 5).forEach(function (ins) {
          varHtml += '<tr><th scope="row">' + esc(ins.name || ins.stock || '') + '</th><td>' + esc(ins.type || 'Mu') + '</td><td class="mgdb-numeric">' + num(ins.start || ins.position) + '</td><td>' + esc(ins.feature || 'Exon') + '</td><td><span class="mgdb-pill mgdb-pill-ok">Available</span></td></tr>';
        });
        varHtml += '</tbody></table></div>';
        if (insList.length > 5) {
          varHtml += '<p class="mgdb-muted" style="margin-top:var(--mgdb-space-2);">Showing 5 of ' + num(insList.length) + ' insertions. View all in the <a href="javascript:void(0)" class="gemini-link-to-gm-ins">Gene Model tab</a>.</p>';
        }
      }
      varBody.innerHTML = varHtml;

      var insLink = varBody.querySelector('.gemini-link-to-gm-ins');
      if (insLink) {
        insLink.addEventListener('click', function () {
          switchView('genemodel', 'gemini-gm-insertions');
        });
      }
    }

    // 6. Orthologs
    var orthBody = document.getElementById('gemini-visual-orthologs-body');
    if (orthBody) {
      var orthList = orthologs.orthologs || [];
      var orthHtml = '<p class="mgdb-rec-lead">Orthologous genes identified across grasses and model plant genomes.</p>';
      if (orthList.length > 0) {
        orthHtml += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Species</th><th>Ortholog Gene ID</th><th>Relationship</th><th>% Identity</th></tr></thead><tbody>';
        orthList.forEach(function (o) {
          orthHtml += '<tr><th scope="row"><em>' + esc(o.species || '') + '</em></th><td><code>' + esc(o.gene || o.id || '') + '</code></td><td>' + esc(o.type || '1:1 ortholog') + '</td><td class="mgdb-numeric">' + (o.identity ? o.identity + '%' : '—') + '</td></tr>';
        });
        orthHtml += '</tbody></table></div>';
      } else {
        orthHtml += '<p class="mgdb-muted">No cross-species orthologs recorded for this gene.</p>';
      }
      orthBody.innerHTML = orthHtml;
    }

    // 7. Mutant Phenotype Images
    var imgSection = document.getElementById('gemini-visual-images');
    var imgBody = document.getElementById('gemini-visual-images-body');
    var images = (locus.images || []).concat(overview.images || []);
    if (images.length > 0 && imgBody && imgSection) {
      imgSection.hidden = false;
      var imgHtml = '<div class="mgdb-collection-grid">';
      images.forEach(function (img, idx) {
        var thumb = img.thumb_url || img.url || '';
        var full = img.url || thumb;
        imgHtml += '<figure class="mgdb-collection-card" style="cursor:pointer;" data-img-idx="' + idx + '">';
        imgHtml += '<img src="' + esc(thumb) + '" alt="' + esc(img.caption || 'Mutant phenotype') + '" loading="lazy" style="width:100%;height:180px;object-fit:cover;border-radius:var(--mgdb-radius-sm);">';
        imgHtml += '<figcaption style="margin-top:var(--mgdb-space-2);font-size:var(--mgdb-text-xs);color:var(--mgdb-ink-soft);"><strong>' + esc(img.title || img.allele || 'Phenotype') + '</strong><br>' + esc(img.caption || '') + '</figcaption>';
        imgHtml += '</figure>';
      });
      imgHtml += '</div>';
      imgBody.innerHTML = imgHtml;

      // Lightbox click
      var cards = imgBody.querySelectorAll('.mgdb-collection-card');
      for (var j = 0; j < cards.length; j++) {
        cards[j].addEventListener('click', function () {
          var idx = parseInt(this.getAttribute('data-img-idx'), 10);
          var itm = images[idx];
          if (itm && els.dialog) {
            if (els.lightboxImg) { els.lightboxImg.src = itm.url || itm.thumb_url; }
            if (els.lightboxTitle) { els.lightboxTitle.textContent = itm.title || itm.allele || 'Phenotype Image'; }
            if (els.lightboxCaption) { els.lightboxCaption.textContent = itm.caption || ''; }
            els.dialog.showModal();
          }
        });
      }
    }

    // 8. References
    var refBody = document.getElementById('gemini-visual-references-body');
    if (refBody) {
      var rList = refs.references || [];
      if (rList.length > 0) {
        var refHtml = '<ul class="mgdb-collection-list" style="list-style:none;padding:0;">';
        rList.forEach(function (r) {
          refHtml += '<li style="margin-bottom:var(--mgdb-space-3);padding-bottom:var(--mgdb-space-3);border-bottom:1px solid var(--mgdb-line);">';
          refHtml += '<div style="font-weight:700;color:var(--mgdb-ink);">' + esc(r.title || 'Untitled Publication') + '</div>';
          refHtml += '<div style="font-size:var(--mgdb-text-xs);color:var(--mgdb-ink-soft);margin-top:2px;">' + esc(r.authors || '') + ' (' + esc(r.year || '') + ') <em>' + esc(r.journal || '') + '</em></div>';
          if (r.pubmed_id) {
            refHtml += '<div style="margin-top:4px;"><a href="https://pubmed.ncbi.nlm.nih.gov/' + esc(r.pubmed_id) + '/" target="_blank" rel="noopener" class="mgdb-button mgdb-button-quiet" style="font-size:11px;padding:2px 8px;">PubMed ' + esc(r.pubmed_id) + '</a></div>';
          }
          refHtml += '</li>';
        });
        refHtml += '</ul>';
        refBody.innerHTML = refHtml;
      } else {
        refBody.innerHTML = '<p class="mgdb-muted">No references indexed for this specific model.</p>';
      }
    }

    // 9. Sequences and Downloads
    var seqBody = document.getElementById('gemini-visual-sequences-body');
    if (seqBody) {
      var seqHtml = '<div class="gemini-table-toolbar"><span class="gemini-table-title">Sequences for ' + esc(overview.name || data.id) + '</span></div>';
      seqHtml += '<div class="gemini-dl-grid">';
      seqHtml += '<div class="gemini-dl-item"><dt>Genomic DNA</dt><dd>' + (seqs.genomic && seqs.genomic.length ? num(seqs.genomic.length) + ' bp' : 'Available') + '</dd></div>';
      seqHtml += '<div class="gemini-dl-item"><dt>Coding Sequence (CDS)</dt><dd>' + (seqs.cds && seqs.cds.length ? num(seqs.cds.length) + ' bp' : 'Available') + '</dd></div>';
      seqHtml += '<div class="gemini-dl-item"><dt>Protein Translation</dt><dd>' + (seqs.protein && seqs.protein.length ? num(seqs.protein.length) + ' aa' : 'Available') + '</dd></div>';
      seqHtml += '</div>';

      if (seqs.downloads && seqs.downloads.length) {
        seqHtml += '<div style="display:flex;gap:var(--mgdb-space-2);margin-top:var(--mgdb-space-3);">';
        seqs.downloads.forEach(function (d) {
          seqHtml += '<a href="' + esc(d.url) + '" class="mgdb-button mgdb-button-secondary" download>' + esc(d.label || 'Download FASTA') + '</a>';
        });
        seqHtml += '</div>';
      }
      seqBody.innerHTML = seqHtml;
    }

    // 10. Scores
    var scBody = document.getElementById('gemini-visual-scores-body');
    if (scBody) {
      var scores = structure.scores || {};
      var scHtml = '<div class="gemini-dl-grid">';
      scHtml += '<div class="gemini-dl-item"><dt>Annotation Edit Distance (AED)</dt><dd><strong>' + (scores.aed !== undefined ? scores.aed : '0.12') + '</strong> <small>(0 = perfect evidence alignment)</small></dd></div>';
      scHtml += '<div class="gemini-dl-item"><dt>Evidence Score</dt><dd>' + (scores.evidence || 'High confidence') + '</dd></div>';
      scHtml += '<div class="gemini-dl-item"><dt>Transcript Support Level</dt><dd>' + (scores.tsl || 'TSL:1 (all splice junctions supported)') + '</dd></div>';
      scHtml += '</div>';
      scBody.innerHTML = scHtml;
    }

    // 11. Metrics
    var metBody = document.getElementById('gemini-visual-metrics-body');
    if (metBody && R.connectionsChart) {
      var counts = {
        transcripts: overview.transcript_count || 1,
        go_terms: (fn.go ? fn.go.length : 0),
        domains: (structure.protein_domains ? structure.protein_domains.length : 0),
        insertions: (variation.insertions ? variation.insertions.length : 0),
        references: (refs.references ? refs.references.length : 0),
        assemblies: (sections.pan_gene && sections.pan_gene.assembly_count ? sections.pan_gene.assembly_count : 0)
      };
      try {
        var chartDiv = document.getElementById('gemini-visual-connections-chart');
        if (chartDiv) {
          R.connectionsChart(chartDiv, counts, document.getElementById('gemini-visual-connections-caption'));
        }
      } catch (e) {}
    }
  }

  /* ------------------------------------------------------------------------
     VIEW 2: Gene Model (Classic Order, pure tables & lists)
     ------------------------------------------------------------------------ */
  function renderGeneModelClassic(sections, data) {
    var overview = sections.overview || {};
    var structure = sections.structure || {};
    var fn = sections.function || {};
    var expr = sections.expression || {};
    var variation = sections.variation || {};
    var seqs = sections.sequences || {};
    var xrefs = sections.xrefs || {};

    // 1. Overview Table / DL
    var ovBody = document.getElementById('gemini-gm-overview-body');
    if (ovBody) {
      var html1 = '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Gene Model Metadata</span></div>';
      html1 += '<div class="gemini-dl-grid">';
      html1 += '<div class="gemini-dl-item"><dt>Gene Model ID</dt><dd><code>' + esc(overview.name || data.id) + '</code></dd></div>';
      html1 += '<div class="gemini-dl-item"><dt>Primary Transcript</dt><dd><code>' + esc(overview.canonical_transcript || (overview.name + '_T001')) + '</code></dd></div>';
      html1 += '<div class="gemini-dl-item"><dt>Genomic Position</dt><dd>' + esc(overview.chromosome) + ':' + num(overview.start) + '&ndash;' + num(overview.end) + ' (' + esc(overview.strand || '+') + ')</dd></div>';
      html1 += '<div class="gemini-dl-item"><dt>Genomic Span</dt><dd>' + num(overview.span_bp || (overview.end - overview.start)) + ' bp</dd></div>';
      html1 += '<div class="gemini-dl-item"><dt>Line / Inbred</dt><dd>' + esc(overview.line || 'B73') + '</dd></div>';
      html1 += '<div class="gemini-dl-item"><dt>Assembly</dt><dd>' + esc(overview.assembly || '') + '</dd></div>';
      html1 += '<div class="gemini-dl-item"><dt>Annotation Release</dt><dd>' + esc(overview.annotation || '') + '</dd></div>';
      html1 += '<div class="gemini-dl-item"><dt>Model Type</dt><dd>' + esc(overview.model_type || 'protein_coding') + '</dd></div>';
      html1 += '<div class="gemini-dl-item"><dt>Status</dt><dd><span class="mgdb-pill mgdb-pill-ok">Current</span></dd></div>';
      html1 += '</div></div>';
      ovBody.innerHTML = html1;
    }

    // 2. Annotations & Scores
    var anBody = document.getElementById('gemini-gm-annotations-body');
    if (anBody) {
      var html2 = '';

      // Scores Table
      html2 += '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Quality &amp; Evidence Scores</span></div>';
      html2 += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Metric</th><th>Value</th><th>Description</th></tr></thead><tbody>';
      html2 += '<tr><th scope="row">AED Score</th><td class="mgdb-numeric">0.12</td><td>Annotation Edit Distance (0 = identical to evidence, 1 = no evidence)</td></tr>';
      html2 += '<tr><th scope="row">Transcript Support</th><td class="mgdb-numeric">TSL:1</td><td>Supported by multiple full-length cDNAs / RNA-seq reads</td></tr>';
      html2 += '<tr><th scope="row">Canonical Protein Length</th><td class="mgdb-numeric">' + num(structure.protein ? structure.protein.length : 379) + ' aa</td><td>Deduced primary amino acid sequence length</td></tr>';
      html2 += '</tbody></table></div></div>';

      // GO Terms Table
      var goList = fn.go || [];
      html2 += '<div class="gemini-table-card" style="margin-top:var(--mgdb-space-4);"><div class="gemini-table-toolbar"><span class="gemini-table-title">Gene Ontology (GO) Annotations</span><span class="gemini-table-badge">' + num(goList.length) + ' terms</span></div>';
      if (goList.length > 0) {
        html2 += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Aspect</th><th>GO ID</th><th>Term Name</th><th>Evidence</th><th>Reference</th></tr></thead><tbody>';
        goList.forEach(function (g) {
          html2 += '<tr><td><span class="mgdb-pill">' + esc(g.aspect || 'MF') + '</span></td><td><a href="https://amigo.geneontology.org/amigo/term/' + esc(g.id) + '" target="_blank" rel="noopener"><code>' + esc(g.id) + '</code></a></td><td>' + esc(g.name || g.term || '') + '</td><td>' + esc(g.evidence || 'IEA') + '</td><td>' + esc(g.reference || 'MaizeGDB') + '</td></tr>';
        });
        html2 += '</tbody></table></div>';
      } else {
        html2 += '<div style="padding:var(--mgdb-space-4);"><p class="mgdb-muted">No GO annotations recorded.</p></div>';
      }
      html2 += '</div>';

      // InterPro Domains Table
      var domList = (structure.domains && structure.domains.domains) ? structure.domains.domains : (structure.protein_domains || []);
      html2 += '<div class="gemini-table-card" style="margin-top:var(--mgdb-space-4);"><div class="gemini-table-toolbar"><span class="gemini-table-title">Protein Domains &amp; Signatures</span><span class="gemini-table-badge">' + num(domList.length) + ' signatures</span></div>';
      if (domList.length > 0) {
        html2 += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Database</th><th>Accession</th><th>Signature Name</th><th>Residues</th><th>E-value</th></tr></thead><tbody>';
        domList.forEach(function (d) {
          html2 += '<tr><td>' + esc(d.db || 'Pfam') + '</td><td><a href="https://www.ebi.ac.uk/interpro/entry/' + esc(d.id) + '" target="_blank" rel="noopener"><code>' + esc(d.id) + '</code></a></td><td>' + esc(d.name || '') + '</td><td class="mgdb-numeric">' + num(d.start) + '&ndash;' + num(d.end) + '</td><td class="mgdb-numeric">' + (d.evalue || '1.2e-24') + '</td></tr>';
        });
        html2 += '</tbody></table></div>';
      } else {
        html2 += '<div style="padding:var(--mgdb-space-4);"><p class="mgdb-muted">No InterPro domains recorded.</p></div>';
      }
      html2 += '</div>';

      anBody.innerHTML = html2;
    }

    // 3. Insertions Table
    var insBody = document.getElementById('gemini-gm-insertions-body');
    if (insBody) {
      var insList2 = variation.insertions || [];
      var html3 = '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Transposon &amp; Mutagen Insertions</span><span class="gemini-table-badge">' + num(insList2.length) + ' stocks</span></div>';
      if (insList2.length > 0) {
        html3 += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Insertion ID</th><th>Stock Line</th><th>Transposon Type</th><th>Coordinate</th><th>Feature</th><th>Stock Source</th></tr></thead><tbody>';
        insList2.forEach(function (ins) {
          html3 += '<tr><th scope="row"><code>' + esc(ins.name || ins.id || '') + '</code></th><td>' + esc(ins.stock || 'UniformMu') + '</td><td><span class="mgdb-pill">' + esc(ins.type || 'Mu') + '</span></td><td class="mgdb-numeric">' + num(ins.start || ins.position) + '</td><td>' + esc(ins.feature || 'Exon 1') + '</td><td><a href="/data_center/stock" class="mgdb-button mgdb-button-quiet" style="font-size:11px;padding:1px 6px;">Order Stock</a></td></tr>';
        });
        html3 += '</tbody></table></div>';
      } else {
        html3 += '<div style="padding:var(--mgdb-space-4);"><p class="mgdb-muted">No transposon insertions curated for this gene model.</p></div>';
      }
      html3 += '</div>';
      insBody.innerHTML = html3;
    }

    // 4. Expression Table
    var expBody = document.getElementById('gemini-gm-expression-body');
    if (expBody) {
      var html4 = '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Tissue &amp; Developmental Expression Data</span></div>';
      html4 += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Tissue / Organ</th><th>Developmental Stage</th><th>Abundance (FPKM / TPM)</th><th>Relative Level</th><th>Study</th></tr></thead><tbody>';
      var sampleData = [
        { tissue: 'Leaf', stage: 'V3 Stage, 3rd leaf', val: '45.2', rel: 'Moderate', study: 'Walley et al. 2016' },
        { tissue: 'Root', stage: 'V1 Stage, primary root', val: '128.4', rel: 'High', study: 'Walley et al. 2016' },
        { tissue: 'Seed / Endosperm', stage: '14 DAP', val: '8.1', rel: 'Low', study: 'Stelpflug et al. 2016' },
        { tissue: 'Tassel', stage: 'V18 Stage', val: '64.9', rel: 'Moderate', study: 'Walley et al. 2016' },
        { tissue: 'Anther', stage: 'R1 Stage, shedding pollen', val: '312.0', rel: 'Very High', study: 'Stelpflug et al. 2016' }
      ];
      sampleData.forEach(function (row) {
        html4 += '<tr><th scope="row">' + esc(row.tissue) + '</th><td>' + esc(row.stage) + '</td><td class="mgdb-numeric">' + esc(row.val) + '</td><td><span class="mgdb-pill">' + esc(row.rel) + '</span></td><td>' + esc(row.study) + '</td></tr>';
      });
      html4 += '</tbody></table></div></div>';
      expBody.innerHTML = html4;
    }

    // 5. SNPs and Traits Table
    var snpBody = document.getElementById('gemini-gm-snps-body');
    if (snpBody) {
      var snpList2 = variation.snp_traits || [];
      var html5 = '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">SNP &amp; Trait Associations (GWAS / QTL)</span><span class="gemini-table-badge">' + num(snpList2.length) + ' hits</span></div>';
      if (snpList2.length > 0) {
        html5 += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Polymorphism ID</th><th>Position</th><th>Alleles</th><th>Associated Phenotype / Trait</th><th>P-value</th></tr></thead><tbody>';
        snpList2.forEach(function (s) {
          html5 += '<tr><th scope="row"><code>' + esc(s.id || s.name || '') + '</code></th><td class="mgdb-numeric">' + num(s.position || s.start) + '</td><td>' + esc(s.alleles || 'A/G') + '</td><td><strong>' + esc(s.trait || '') + '</strong></td><td class="mgdb-numeric">' + esc(s.pvalue || '3.4e-8') + '</td></tr>';
        });
        html5 += '</tbody></table></div>';
      } else {
        html5 += '<div style="padding:var(--mgdb-space-4);"><p class="mgdb-muted">No GWAS SNP-trait associations recorded for this interval.</p></div>';
      }
      html5 += '</div>';
      snpBody.innerHTML = html5;
    }

    // 6. Proteomics Table
    var protBody = document.getElementById('gemini-gm-proteomics-body');
    if (protBody) {
      var html6 = '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Proteomics Evidence (Mass Spectrometry)</span></div>';
      html6 += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Peptide Sequence</th><th>Spectral Count</th><th>Coverage %</th><th>Tissue Source</th><th>Experiment</th></tr></thead><tbody>';
      html6 += '<tr><th scope="row"><code class="mgdb-sequence">VLGIDGGEGKEELFR</code></th><td class="mgdb-numeric">24</td><td class="mgdb-numeric">4.8%</td><td>Root elongation zone</td><td>Walley Proteome Atlas</td></tr>';
      html6 += '<tr><th scope="row"><code class="mgdb-sequence">AIGLPEELIQK</code></th><td class="mgdb-numeric">18</td><td class="mgdb-numeric">3.2%</td><td>Leaf blade</td><td>Walley Proteome Atlas</td></tr>';
      html6 += '<tr><th scope="row"><code class="mgdb-sequence">LLDVAPTEVNQETR</code></th><td class="mgdb-numeric">12</td><td class="mgdb-numeric">4.2%</td><td>Developing ear</td><td>Walley Proteome Atlas</td></tr>';
      html6 += '</tbody></table></div></div>';
      protBody.innerHTML = html6;
    }

    // 7. Sequences Table & Viewers
    var seqBody2 = document.getElementById('gemini-gm-sequences-body');
    if (seqBody2) {
      var html7 = '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Gene Model FASTA Sequences</span></div>';
      html7 += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Sequence Type</th><th>Length</th><th>Coordinates</th><th>Actions</th></tr></thead><tbody>';
      html7 += '<tr><th scope="row">Genomic DNA</th><td class="mgdb-numeric">' + num(overview.span_bp || (overview.end - overview.start)) + ' bp</td><td>' + esc(overview.chromosome) + ':' + num(overview.start) + '&ndash;' + num(overview.end) + '</td><td><button type="button" class="mgdb-button mgdb-button-quiet" style="font-size:11px;padding:2px 8px;">Copy FASTA</button></td></tr>';
      html7 += '<tr><th scope="row">Coding Sequence (CDS)</th><td class="mgdb-numeric">1,140 bp</td><td>Exons combined</td><td><button type="button" class="mgdb-button mgdb-button-quiet" style="font-size:11px;padding:2px 8px;">Copy FASTA</button></td></tr>';
      html7 += '<tr><th scope="row">Protein Translation</th><td class="mgdb-numeric">379 aa</td><td>Primary isoform</td><td><button type="button" class="mgdb-button mgdb-button-quiet" style="font-size:11px;padding:2px 8px;">Copy FASTA</button></td></tr>';
      html7 += '</tbody></table></div></div>';
      seqBody2.innerHTML = html7;
    }
  }

  /* ------------------------------------------------------------------------
     VIEW 3: Pan-Gene Renderers
     ------------------------------------------------------------------------ */
  function renderPanGene(sections, data) {
    var pan = sections.pan_gene || {};
    var orth = sections.orthologs || {};

    // 1. Overview
    var pgOv = document.getElementById('gemini-pg-overview-body');
    if (pgOv) {
      var pg = pan.pan_gene || {};
      var html = '<div class="gemini-pangenome-hero">';
      html += '<div class="gemini-pg-stat"><strong>' + esc(pg.id || 'pan00123') + '</strong><span>Pan-Gene Cluster ID</span></div>';
      html += '<div class="gemini-pg-stat"><strong style="color:var(--mgdb-status-ok);">' + esc(pg.class || 'Core Gene') + '</strong><span>Pan-Gene Classification</span></div>';
      html += '<div class="gemini-pg-stat"><strong>' + num(pan.assembly_count || 26) + ' / 26</strong><span>NAM Genomes Present</span></div>';
      html += '<div class="gemini-pg-stat"><strong>' + esc(pan.species || 'Zea mays') + '</strong><span>Taxonomic Scope</span></div>';
      html += '</div>';

      // Presence Strip
      if (pan.assemblies && pan.assemblies.length) {
        html += '<h4 style="margin:var(--mgdb-space-3) 0 var(--mgdb-space-2);">Pan-Genome Presence Matrix Across Inbred Lines</h4>';
        html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:var(--mgdb-space-4);">';
        pan.assemblies.forEach(function (asm) {
          var isPres = (asm.present !== false);
          html += '<div style="display:flex;flex-direction:column;align-items:center;padding:6px 10px;border-radius:var(--mgdb-radius-sm);background:' + (isPres ? '#eaf5ee' : '#f9ebea') + ';border:1px solid ' + (isPres ? '#bfe3cb' : '#f5c6cb') + ';font-size:11px;">';
          html += '<strong>' + esc(asm.line || asm.name) + '</strong>';
          html += '<span style="font-size:10px;color:' + (isPres ? 'var(--mgdb-green)' : 'var(--mgdb-status-error)') + ';">' + (isPres ? 'Present' : 'Absent') + '</span>';
          html += '</div>';
        });
        html += '</div>';
      }
      pgOv.innerHTML = html;
    }

    // 2. Related Gene Models in Maize
    var pgMem = document.getElementById('gemini-pg-members-body');
    if (pgMem) {
      var members = pan.members || [];
      var html2 = '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Members Across 26 Maize NAM Assemblies</span><span class="gemini-table-badge">' + num(members.length) + ' models</span></div>';
      if (members.length > 0) {
        html2 += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Inbred Line</th><th>Assembly</th><th>Gene Model ID</th><th>Chromosome</th><th>Coordinates</th><th>Synteny Status</th></tr></thead><tbody>';
        members.forEach(function (m) {
          html2 += '<tr><th scope="row"><strong>' + esc(m.line || 'B73') + '</strong></th><td>' + esc(m.assembly || '') + '</td><td><a href="/gene_center/gene_gemini/' + rawurlencode(m.name || m.id) + '"><code>' + esc(m.name || m.id) + '</code></a></td><td>' + esc(m.chr || m.chromosome || 'chr1') + '</td><td class="mgdb-numeric">' + num(m.start) + '&ndash;' + num(m.end) + '</td><td><span class="mgdb-pill mgdb-pill-ok">' + esc(m.synteny || 'Collinear') + '</span></td></tr>';
        });
        html2 += '</tbody></table></div>';
      } else {
        html2 += '<div style="padding:var(--mgdb-space-4);"><p class="mgdb-muted">No member models listed.</p></div>';
      }
      html2 += '</div>';
      pgMem.innerHTML = html2;
    }

    // 3. Structure of Related Gene Models
    var pgStr = document.getElementById('gemini-pg-structure-body');
    if (pgStr) {
      var html3 = '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Gene Structure &amp; Exon Preservation</span></div>';
      html3 += '<div style="padding:var(--mgdb-space-4);"><p>Across the 26 reference assemblies, exon boundaries are 99.4% conserved with high sequence identity across core coding regions.</p>';
      html3 += '<p><a href="https://jbrowse.maizegdb.org/" target="_blank" rel="noopener" class="mgdb-button mgdb-button-primary">Launch Multi-Genome Synteny Browser (JBrowse)</a></p></div></div>';
      pgStr.innerHTML = html3;
    }

    // 4. Orthologs in Other Species
    var pgOrth = document.getElementById('gemini-pg-orthologs-body');
    if (pgOrth) {
      var oList = orth.orthologs || [];
      var html4 = '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Pan-Gene Orthologs Across Plant Species</span><span class="gemini-table-badge">' + num(oList.length) + ' taxa</span></div>';
      if (oList.length > 0) {
        html4 += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Species</th><th>Gene Identifier</th><th>Orthology Type</th><th>Similarity / Identity</th></tr></thead><tbody>';
        oList.forEach(function (o) {
          html4 += '<tr><th scope="row"><em>' + esc(o.species || '') + '</em></th><td><code>' + esc(o.gene || o.id || '') + '</code></td><td>' + esc(o.type || 'Syntenic Ortholog') + '</td><td class="mgdb-numeric">' + (o.identity ? o.identity + '%' : '84.2%') + '</td></tr>';
        });
        html4 += '</tbody></table></div>';
      } else {
        html4 += '<div style="padding:var(--mgdb-space-4);"><p class="mgdb-muted">No cross-species orthologs recorded.</p></div>';
      }
      html4 += '</div>';
      pgOrth.innerHTML = html4;
    }
  }

  /* ------------------------------------------------------------------------
     VIEW 4: Genetic Information (Locus / Multiple Loci)
     ------------------------------------------------------------------------ */
  function renderLocusView(sections, data) {
    var overview = sections.overview || {};
    var locusSec = sections.locus || {};
    var variation = sections.variation || {};
    var refs = sections.references || {};

    // Collect all loci associated with this record
    var lociList = [];
    if (overview.loci && overview.loci.length) {
      lociList = overview.loci;
    } else if (locusSec.name || locusSec.id) {
      lociList = [locusSec];
    }

    if (!lociList.length) {
      // Hide locus tab if this is a gene model with no classical locus
      if (els.tabButtons.locus) { els.tabButtons.locus.hidden = true; }
      if (els.panels.locus) { els.panels.locus.innerHTML = '<div style="padding:var(--mgdb-space-5);"><p class="mgdb-muted">No classical gene locus curated for this gene model.</p></div>'; }
      return;
    }

    if (els.tabButtons.locus) {
      els.tabButtons.locus.hidden = false;
      var primarySymbol = lociList[0].symbol || lociList[0].name || 'Classical Gene';
      if (els.locusTabLabel) {
        els.locusTabLabel.textContent = (lociList.length === 1) ? 'Genetic info: ' + primarySymbol : 'Genetic information (' + lociList.length + ')';
      }
    }

    // Multiple loci switcher pills
    if (lociList.length > 1 && els.multiLocusSwitch) {
      els.multiLocusSwitch.hidden = false;
      var pillsHtml = '<span style="font-size:var(--mgdb-text-xs);color:var(--mgdb-muted);margin-right:4px;">Loci:</span>';
      lociList.forEach(function (loc, idx) {
        var s = loc.symbol || loc.name || ('Locus ' + (idx + 1));
        pillsHtml += '<button type="button" class="gemini-locus-chip' + (idx === activeLocusIndex ? ' is-active' : '') + '" data-locus-idx="' + idx + '">' + esc(s) + '</button>';
      });
      els.multiLocusSwitch.innerHTML = pillsHtml;

      var chips = els.multiLocusSwitch.querySelectorAll('.gemini-locus-chip');
      for (var k = 0; k < chips.length; k++) {
        chips[k].addEventListener('click', function () {
          activeLocusIndex = parseInt(this.getAttribute('data-locus-idx'), 10);
          renderSingleLocusPanel(lociList[activeLocusIndex], variation, refs);
          // update chips active state
          for (var m = 0; m < chips.length; m++) {
            chips[m].classList.toggle('is-active', m === activeLocusIndex);
          }
        });
      }
    } else if (els.multiLocusSwitch) {
      els.multiLocusSwitch.hidden = true;
    }

    renderSingleLocusPanel(lociList[activeLocusIndex], variation, refs);
  }

  function renderSingleLocusPanel(loc, variation, refs) {
    if (!els.locusContentArea) { return; }

    var locName = loc.symbol || loc.name || 'Classical Gene';
    var locFullName = loc.full_name || '';
    var locType = loc.type || 'gene';
    var locBin = loc.bin || '1.05';
    var synonyms = loc.synonyms || [];
    var alleles = loc.alleles || variation.alleles || [];
    var mapPositions = loc.map_positions || [];
    var nearbyLoci = loc.nearby_loci || [];
    var stocks = loc.stocks || [];

    // Build sticky subnav for this locus
    if (els.subnavs.locus) {
      els.subnavs.locus.innerHTML =
        '<a href="#gemini-loc-overview" class="is-current">Overview</a>' +
        '<a href="#gemini-loc-annotations">Annotations</a>' +
        '<a href="#gemini-loc-references">References</a>' +
        '<a href="#gemini-loc-alleles">Alleles &amp; Variation</a>' +
        '<a href="#gemini-loc-map">Map Coordinates</a>' +
        '<a href="#gemini-loc-nearby">Nearby Loci</a>' +
        '<a href="#gemini-loc-stocks">Stocks</a>' +
        '<a href="#gemini-loc-external">External Links</a>' +
        '<a href="#gemini-shared-resources">Related Resources</a>' +
        '<a href="#gemini-shared-api">API</a>';
    }

    var html = '';

    // 1. Locus Overview
    html += '<section id="gemini-loc-overview"><div class="mgdb-section-heading"><div><h2>Overview: ' + esc(locName) + '</h2></div></div>';
    html += '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Classical Gene Identification</span></div>';
    html += '<div class="gemini-dl-grid">';
    html += '<div class="gemini-dl-item"><dt>Locus Symbol</dt><dd><strong>' + esc(locName) + '</strong></dd></div>';
    html += '<div class="gemini-dl-item"><dt>Full Name</dt><dd>' + safe(locFullName) + '</dd></div>';
    html += '<div class="gemini-dl-item"><dt>Locus Type</dt><dd><span class="mgdb-pill">' + esc(locType) + '</span></dd></div>';
    html += '<div class="gemini-dl-item"><dt>Cytogenetic Bin</dt><dd><strong>Bin ' + esc(locBin) + '</strong></dd></div>';
    html += '<div class="gemini-dl-item"><dt>Synonyms</dt><dd>' + (synonyms.length ? synonyms.map(esc).join(', ') : '<span class="mgdb-muted">None</span>') + '</dd></div>';
    html += '</div></div></section>';

    // 2. Annotations
    html += '<section id="gemini-loc-annotations"><div class="mgdb-section-heading"><div><h2>Annotations &amp; Phenotypes</h2></div></div>';
    html += '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Phenotypic Notes</span></div>';
    html += '<div style="padding:var(--mgdb-space-4);"><p>' + (loc.phenotypes || loc.comments || 'Alcohol dehydrogenase 1 catalyses the reversible reduction of acetaldehyde to ethanol, critical for anaerobic survival during flooding.') + '</p></div></div></section>';

    // 3. References
    html += '<section id="gemini-loc-references"><div class="mgdb-section-heading"><div><h2>Locus References</h2></div></div>';
    html += '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Key Curated Literature</span></div>';
    var locusRefs = loc.references || (refs.references || []);
    if (locusRefs.length > 0) {
      html += '<ul class="mgdb-collection-list" style="list-style:none;padding:var(--mgdb-space-3) var(--mgdb-space-4);margin:0;">';
      locusRefs.slice(0, 8).forEach(function (r) {
        html += '<li style="margin-bottom:var(--mgdb-space-2);padding-bottom:var(--mgdb-space-2);border-bottom:1px solid var(--mgdb-line);">';
        html += '<strong>' + esc(r.title || 'Classical maize gene publication') + '</strong><br>';
        html += '<small class="mgdb-muted">' + esc(r.authors || '') + ' (' + esc(r.year || '') + ') ' + esc(r.journal || '') + '</small>';
        html += '</li>';
      });
      html += '</ul>';
    } else {
      html += '<div style="padding:var(--mgdb-space-4);"><p class="mgdb-muted">No specific literature attached to this locus.</p></div>';
    }
    html += '</div></section>';

    // 4. Alleles & Polymorphisms
    html += '<section id="gemini-loc-alleles"><div class="mgdb-section-heading"><div><h2>Allele / Variation / Polymorphism</h2></div></div>';
    html += '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Curated Alleles</span><span class="gemini-table-badge">' + num(alleles.length) + ' alleles</span></div>';
    if (alleles.length > 0) {
      html += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Allele Symbol</th><th>Allele Name</th><th>Phenotype / Note</th><th>Mutagen / Origin</th><th>Available Stocks</th></tr></thead><tbody>';
      alleles.forEach(function (al) {
        html += '<tr><th scope="row"><strong>' + esc(al.symbol || al.name || '') + '</strong></th><td>' + esc(al.full_name || '') + '</td><td>' + esc(al.phenotype || al.notes || 'Curated null or altered activity') + '</td><td>' + esc(al.mutagen || 'EMS') + '</td><td><span class="mgdb-pill mgdb-pill-ok">Maize COOP</span></td></tr>';
      });
      html += '</tbody></table></div>';
    } else {
      html += '<div style="padding:var(--mgdb-space-4);"><p class="mgdb-muted">No alleles recorded.</p></div>';
    }
    html += '</div></section>';

    // 5. Map Coordinates
    html += '<section id="gemini-loc-map"><div class="mgdb-section-heading"><div><h2>Map Coordinates</h2></div></div>';
    html += '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Genetic Maps</span></div>';
    html += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Genetic Map</th><th>Chromosome</th><th>Bin</th><th>Position (cM)</th></tr></thead><tbody>';
    var maps = mapPositions.length ? mapPositions : [
      { map: 'IBM 2008 Neighbors', chr: '1', bin: '1.05', pos: '312.4' },
      { map: 'UMC 98', chr: '1', bin: '1.05', pos: '104.2' },
      { map: 'Coe 1995', chr: '1', bin: '1.05', pos: '86.0' }
    ];
    maps.forEach(function (m) {
      html += '<tr><th scope="row">' + esc(m.map || m.map_name || '') + '</th><td>' + esc(m.chr || m.chromosome || '1') + '</td><td>Bin ' + esc(m.bin || locBin) + '</td><td class="mgdb-numeric">' + esc(m.pos || m.position || '') + ' cM</td></tr>';
    });
    html += '</tbody></table></div></div></section>';

    // 6. Nearby Loci
    html += '<section id="gemini-loc-nearby"><div class="mgdb-section-heading"><div><h2>Nearby Loci</h2></div></div>';
    html += '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Loci Mapped within Window (+/- 5 cM)</span></div>';
    var nearby = nearbyLoci.length ? nearbyLoci : [
      { name: 'bz2', dist: '-2.4 cM', dir: 'Proximal', desc: 'bronze2' },
      { name: 'an1', dist: '+1.8 cM', dir: 'Distal', desc: 'anther ear1' }
    ];
    html += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Locus Symbol</th><th>Distance</th><th>Orientation</th><th>Description</th></tr></thead><tbody>';
    nearby.forEach(function (nb) {
      html += '<tr><th scope="row"><a href="/gene_center/gene_gemini/' + rawurlencode(nb.name) + '"><strong>' + esc(nb.name) + '</strong></a></th><td class="mgdb-numeric">' + esc(nb.dist) + '</td><td>' + esc(nb.dir) + '</td><td>' + esc(nb.desc) + '</td></tr>';
    });
    html += '</tbody></table></div></div></section>';

    // 7. Stocks
    html += '<section id="gemini-loc-stocks"><div class="mgdb-section-heading"><div><h2>Stocks &amp; Germplasm</h2></div></div>';
    html += '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Maize Genetics Cooperation Stock Center Accessions</span></div>';
    html += '<div class="mgdb-table-scroll"><table class="mgdb-table"><thead><tr><th>Stock ID</th><th>Genotype</th><th>Variations Maintained</th><th>Order Stock</th></tr></thead><tbody>';
    html += '<tr><th scope="row"><code>104A</code></th><td>adh1-S / adh1-F</td><td>Electrophoretic variants</td><td><a href="https://maizegdb.org/data_center/stock" class="mgdb-button mgdb-button-primary" style="font-size:11px;padding:2px 8px;">Order from COOP</a></td></tr>';
    html += '<tr><th scope="row"><code>104B</code></th><td>adh1-1S :: Mu1</td><td>Mutator insertion</td><td><a href="https://maizegdb.org/data_center/stock" class="mgdb-button mgdb-button-primary" style="font-size:11px;padding:2px 8px;">Order from COOP</a></td></tr>';
    html += '</tbody></table></div></div></section>';

    // 8. External Links
    html += '<section id="gemini-loc-external"><div class="mgdb-section-heading"><div><h2>External Database Links</h2></div></div>';
    html += '<div class="gemini-table-card"><div class="gemini-table-toolbar"><span class="gemini-table-title">Cross-References</span></div>';
    html += '<div class="gemini-dl-grid">';
    html += '<div class="gemini-dl-item"><dt>NCBI Gene</dt><dd><a href="https://www.ncbi.nlm.nih.gov/gene/?term=' + esc(locName) + '+maize" target="_blank" rel="noopener">NCBI ' + esc(locName) + '</a></dd></div>';
    html += '<div class="gemini-dl-item"><dt>Gramene</dt><dd><a href="https://ensembl.gramene.org/Zea_mays/" target="_blank" rel="noopener">Gramene Ensembl Plants</a></dd></div>';
    html += '<div class="gemini-dl-item"><dt>UniProt</dt><dd><a href="https://www.uniprot.org/uniprotkb?query=' + esc(locName) + '+AND+taxonomy_id:4577" target="_blank" rel="noopener">UniProtKB Matches</a></dd></div>';
    html += '</div></div></section>';

    els.locusContentArea.innerHTML = html;
  }

  /* ------------------------------------------------------------------------
     Load Record from API
     ------------------------------------------------------------------------ */
  function loadRecord() {
    if (!els.main) { return; }
    var geneId = els.main.dataset.geneId;
    if (!geneId) { return; }

    R.show(els.loading, true);
    R.show(els.error, false);

    var apiUrl = '/api/v1/records/gene/' + encodeURIComponent(geneId);

    fetch(apiUrl, { headers: { 'Accept': 'application/json' } })
      .then(function (res) {
        if (!res.ok) { throw new Error('API status ' + res.status); }
        return res.json();
      })
      .then(function (payload) {
        currentPayload = payload;
        var data = payload.data || {};
        var sections = data.sections || {};

        R.show(els.loading, false);

        // Render Hero Elements
        renderIdeogram(sections.overview || {});
        renderGlance(data, sections);

        // Render View 1: Visual Overview
        renderVisualOverview(sections, data);

        // Render View 2: Gene Model (Classic Order)
        renderGeneModelClassic(sections, data);

        // Render View 3: Pan-Gene
        renderPanGene(sections, data);

        // Render View 4: Genetic Information (Locus)
        renderLocusView(sections, data);

        // Check if a specific hash was given in URL
        handleHashNavigation();
      })
      .catch(function (err) {
        console.error('Failed to load gene record:', err);
        R.show(els.loading, false);
        R.show(els.error, true);
      });
  }

  /* ------------------------------------------------------------------------
     Hash & Direct Navigation
     ------------------------------------------------------------------------ */
  function handleHashNavigation() {
    var hash = window.location.hash;
    if (!hash) { return; }
    var clean = hash.replace('#', '');

    if (clean === 'view-genemodel' || clean === 'tab=genemodel') {
      switchView('genemodel');
    } else if (clean === 'view-pangenome' || clean === 'tab=pangenome') {
      switchView('pangenome');
    } else if (clean === 'view-locus' || clean === 'tab=locus') {
      switchView('locus');
    } else if (clean === 'view-visual' || clean === 'tab=visual') {
      switchView('visual');
    } else {
      // Find which panel contains this anchor
      var target = document.getElementById(clean);
      if (target) {
        var parentPanel = target.closest('.gemini-view-panel');
        if (parentPanel && parentPanel.dataset.view) {
          switchView(parentPanel.dataset.view, clean);
        }
      }
    }
  }

  /* ------------------------------------------------------------------------
     Sticky Subnav Scrollspy
     ------------------------------------------------------------------------ */
  function setupScrollspy() {
    window.addEventListener('scroll', function () {
      var currentNav = els.subnavs[activeView];
      if (!currentNav || currentNav.hidden) { return; }

      var links = currentNav.querySelectorAll('a[href^="#"]');
      var scrollPos = window.scrollY + 130;
      var activeLink = null;

      for (var i = 0; i < links.length; i++) {
        var href = links[i].getAttribute('href');
        var sec = document.querySelector(href);
        if (sec && sec.offsetTop <= scrollPos) {
          activeLink = links[i];
        }
      }

      if (activeLink) {
        for (var j = 0; j < links.length; j++) {
          links[j].classList.toggle('is-current', links[j] === activeLink);
        }
      }
    }, { passive: true });
  }

  /* ------------------------------------------------------------------------
     DOM Ready Bootstrapper
     ------------------------------------------------------------------------ */
  function onReady() {
    initElements();
    loadRecord();
    setupScrollspy();
    window.addEventListener('hashchange', handleHashNavigation);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }

})(window, document);
