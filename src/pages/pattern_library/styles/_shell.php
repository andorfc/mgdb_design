<?php
/*
 * Shared shell for the alternative pattern libraries.
 *
 * The five styles render byte-identical markup. Everything that differs
 * between them lives in css/pattern-style-<n>.css, which is the point: a
 * comparison is only worth anything if the content is held constant. If a
 * style needs different structure it has to earn it through CSS, the same way
 * it would on a real page.
 *
 * A page under styles/<n>/ sets $PL before including this file.
 */

if (!isset($PL)) { http_response_code(500); exit('no style selected'); }

$PL_STYLES = array(
  1 => array('name' => 'Journal',    'tagline' => 'Editorial, printed-page typography'),
  2 => array('name' => 'Console',    'tagline' => 'Dense, monospaced, built for reading data'),
  3 => array('name' => 'Grid',       'tagline' => 'Swiss typographic grid, high type contrast'),
  4 => array('name' => 'Prairie',    'tagline' => 'Open, rounded, light on its feet'),
  5 => array('name' => 'Instrument', 'tagline' => 'Dark ground, luminous data'),
);

$me = $PL_STYLES[$PL];
function e($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en" data-style="<?php echo $PL; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($me['name']); ?> — Pattern library <?php echo $PL; ?> | MaizeGDB</title>
<meta name="robots" content="noindex, nofollow">
<meta name="description" content="Alternative design language <?php echo $PL; ?> of 5 for MaizeGDB, rendered as a full pattern library for comparison.">
<link rel="stylesheet" href="/css/pattern-style-base.css?v=<?php echo @filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/pattern-style-base.css'); ?>">
<link rel="stylesheet" href="/css/pattern-style-<?php echo $PL; ?>.css?v=<?php echo @filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/pattern-style-' . $PL . '.css'); ?>">
</head>
<body>

<!-- Comparison bar. Not part of any style; it is the harness that makes the
     five comparable, so it is deliberately neutral in all of them. -->
<nav class="pl-switch" aria-label="Choose a style">
  <span class="pl-switch-label">Style</span>
  <?php foreach ($PL_STYLES as $n => $s): ?>
    <a class="pl-switch-link<?php echo $n === $PL ? ' is-current' : ''; ?>"
       href="/pattern_library/styles/<?php echo $n; ?>/"
       <?php echo $n === $PL ? 'aria-current="page"' : ''; ?>><?php echo $n . '. ' . e($s['name']); ?></a>
  <?php endforeach; ?>
  <span class="pl-switch-sep" aria-hidden="true"></span>
  <a class="pl-switch-link" href="/pattern_library/styles/">All five</a>
  <a class="pl-switch-link" href="/pattern_library/">Current site</a>
</nav>

<a class="pl-skip" href="#pl-main">Skip to main content</a>

<header class="pl-header">
  <div class="pl-shell pl-header-inner">
    <a class="pl-brand" href="/pattern_library/styles/<?php echo $PL; ?>/">
      <span class="pl-brand-mark" aria-hidden="true">
        <svg viewBox="0 0 40 40" role="presentation" focusable="false">
          <path d="M20 3c5 5 7 11 7 17s-2 12-7 17c-5-5-7-11-7-17s2-12 7-17z" fill="currentColor"/>
          <circle cx="20" cy="14" r="2.1" fill="var(--pl-mark-dot)"/>
          <circle cx="20" cy="21" r="2.1" fill="var(--pl-mark-dot)"/>
          <circle cx="20" cy="28" r="2.1" fill="var(--pl-mark-dot)"/>
        </svg>
      </span>
      <span class="pl-brand-text"><strong>MaizeGDB</strong><span>Genetics and Genomics Database</span></span>
    </a>
    <nav class="pl-nav" aria-label="Main">
      <a href="#pl-headers">Genomes</a>
      <a href="#pl-metrics">Data hubs</a>
      <a href="#pl-table">Tools</a>
      <a href="#pl-forms">Community</a>
      <a href="#pl-tokens">About</a>
    </nav>
    <form class="pl-headsearch" role="search" onsubmit="return false">
      <label class="pl-sr" for="pl-headq">Search MaizeGDB</label>
      <input id="pl-headq" type="search" placeholder="Gene, locus, stock, QTL…">
      <button type="submit">Search</button>
    </form>
  </div>
</header>

<main class="pl-shell" id="pl-main">

  <nav class="pl-crumb" aria-label="Breadcrumb">
    <a href="/">Home</a><span aria-hidden="true">/</span><a href="/pattern_library/styles/">Pattern libraries</a><span aria-hidden="true">/</span><span aria-current="page"><?php echo e($me['name']); ?></span>
  </nav>

  <!-- 1. Hero ------------------------------------------------------------ -->
  <section class="pl-hero" aria-labelledby="pl-title">
    <div class="pl-hero-body">
      <p class="pl-eyebrow">Design language <?php echo $PL; ?> of 5</p>
      <h1 id="pl-title"><?php echo e($me['name']); ?></h1>
      <p class="pl-lede"><?php echo e($me['tagline']); ?>. Every component below is rendered from the same markup as the other four, so what you are comparing is the design language and nothing else.</p>
      <p class="pl-actions">
        <a class="pl-btn pl-btn-primary" href="#pl-table">See a data table</a>
        <a class="pl-btn pl-btn-secondary" href="#pl-tokens">Design tokens</a>
      </p>
    </div>
    <aside class="pl-hero-side">
      <p class="pl-kicker">Reference genome</p>
      <p class="pl-hero-figure">Zm-B73<br>REFERENCE<br>NAM-5.0</p>
      <dl class="pl-hero-facts">
        <div><dt>Assembly</dt><dd>2,178.3 Mb</dd></div>
        <div><dt>Gene models</dt><dd>39,756</dd></div>
        <div><dt>Contig N50</dt><dd>47.9 Mb</dd></div>
      </dl>
    </aside>
  </section>

  <!-- 2. Headings and prose ---------------------------------------------- -->
  <section id="pl-headers" aria-labelledby="pl-headers-t">
    <div class="pl-sechead">
      <div>
        <p class="pl-eyebrow">Page identity</p>
        <h2 id="pl-headers-t">Typography and prose</h2>
      </div>
      <p class="pl-sechead-note">The type scale carries most of a design language. Headings, lead copy, body text, captions, and inline scientific notation all appear here at their real sizes.</p>
    </div>

    <div class="pl-prose-layout">
      <div class="pl-prose">
        <h3>Nomenclature for gene symbols</h3>
        <p>A maize gene symbol is set in italic lower case when the allele is recessive — <i>dek12</i> — and with an initial capital when it is dominant, as in <i>Dek12</i>. The gene product carries the same symbol in upright capitals, <strong>DEK12</strong>, which is what separates a statement about a locus from a statement about a protein.</p>
        <p>Gene model identifiers are not symbols and are never italicised: <code>Zm00001eb000010</code> names a model in a specific annotation of a specific assembly, and it changes when either changes. A published symbol outlives both.</p>
        <blockquote>
          <p>Where a symbol and a model identifier disagree, the symbol is the claim and the identifier is the evidence. Cite both.</p>
          <cite>Maize Genetics Nomenclature, section 3</cite>
        </blockquote>
        <h4>Ordering of loci</h4>
        <ol>
          <li>By chromosome, then by physical coordinate on the reference assembly.</li>
          <li>Where coordinates are unavailable, by genetic map position in centimorgans.</li>
          <li>Unplaced loci last, alphabetically by symbol.</li>
        </ol>
      </div>
      <aside class="pl-aside">
        <h3>At a glance</h3>
        <ul class="pl-tight-list">
          <li>52 genomes re-scanned</li>
          <li>46 Andropogoneae, 6 outgroups</li>
          <li>InterProScan 5.78</li>
          <li>Two annotation arms, never pooled</li>
        </ul>
        <p class="pl-fine">Curated and Helixer gene models are separate measurements of the same genomes.</p>
      </aside>
    </div>
  </section>

  <!-- 3. Metrics ---------------------------------------------------------- -->
  <section id="pl-metrics" aria-labelledby="pl-metrics-t">
    <div class="pl-sechead">
      <div>
        <p class="pl-eyebrow">Summary</p>
        <h2 id="pl-metrics-t">Metric cards</h2>
      </div>
      <p class="pl-sechead-note">The first thing a data page has to answer is how much is in it. Four numbers, one qualifier each.</p>
    </div>
    <div class="pl-metrics">
      <div class="pl-metric">
        <p class="pl-metric-label">Gene models</p>
        <p class="pl-metric-value">39,756</p>
        <p class="pl-metric-note">B73 NAM-5.0, curated</p>
      </div>
      <div class="pl-metric">
        <p class="pl-metric-label">Genome assemblies</p>
        <p class="pl-metric-value">52</p>
        <p class="pl-metric-note">46 Andropogoneae, 6 outgroup</p>
      </div>
      <div class="pl-metric">
        <p class="pl-metric-label">Assembly length</p>
        <p class="pl-metric-value">2,178.3 <span class="pl-metric-unit">Mb</span></p>
        <p class="pl-metric-note">Reference, ungapped</p>
      </div>
      <div class="pl-metric">
        <p class="pl-metric-label">Last curation</p>
        <p class="pl-metric-value pl-metric-text">14 Aug 2026</p>
        <p class="pl-metric-note">Nightly build 4,182</p>
      </div>
    </div>
  </section>

  <!-- 4. Search and cards -------------------------------------------------- -->
  <section id="pl-search" aria-labelledby="pl-search-t">
    <div class="pl-sechead">
      <div>
        <p class="pl-eyebrow">Find</p>
        <h2 id="pl-search-t">Search, filters, and result cards</h2>
      </div>
      <p class="pl-sechead-note">Filtering is live and the filtered view is shareable from the address bar. The empty state is a component, not an afterthought.</p>
    </div>

    <div class="pl-search">
      <div class="pl-search-row">
        <div class="pl-field">
          <label class="pl-label" for="pl-q">Search assemblies</label>
          <p class="pl-hint" id="pl-q-hint">Genotype, assembly name, or submitter. Example: <code>Mo17</code></p>
          <input class="pl-input" id="pl-q" type="search" aria-describedby="pl-q-hint" placeholder="B73">
        </div>
        <button class="pl-btn pl-btn-primary" type="button">Search</button>
        <button class="pl-btn pl-btn-quiet" type="button">Reset</button>
      </div>
      <div class="pl-chips">
        <span class="pl-chips-label">Species</span>
        <button class="pl-chip is-on" type="button" aria-pressed="true">All 52</button>
        <button class="pl-chip" type="button" aria-pressed="false">Zea mays <span>31</span></button>
        <button class="pl-chip" type="button" aria-pressed="false">Tripsacum <span>4</span></button>
        <button class="pl-chip" type="button" aria-pressed="false">Sorghum <span>3</span></button>
        <button class="pl-chip" type="button" aria-pressed="false">Outgroup <span>6</span></button>
        <span class="pl-count" role="status">52 assemblies shown</span>
      </div>
    </div>

    <div class="pl-cards">
      <article class="pl-card">
        <p class="pl-eyebrow">Reference</p>
        <h3><a href="#pl-search">B73 &mdash; NAM-5.0</a></h3>
        <p>The reference assembly for <i>Zea mays</i>, and the coordinate system every other track on this site is projected onto.</p>
        <dl class="pl-card-facts">
          <div><dt>Length</dt><dd>2,178.3 Mb</dd></div>
          <div><dt>Contig N50</dt><dd>47.9 Mb</dd></div>
          <div><dt>Models</dt><dd>39,756</dd></div>
        </dl>
        <p class="pl-card-tags"><span class="pl-pill pl-pill-ok">Current</span> <span class="pl-pill pl-pill-info">Curated</span></p>
      </article>
      <article class="pl-card">
        <p class="pl-eyebrow">Diversity panel</p>
        <h3><a href="#pl-search">Mo17 &mdash; CAU-2.0</a></h3>
        <p>The second most-used inbred in maize genetics, and the usual partner in a B73 &times; Mo17 comparison.</p>
        <dl class="pl-card-facts">
          <div><dt>Length</dt><dd>2,183.0 Mb</dd></div>
          <div><dt>Contig N50</dt><dd>19.6 Mb</dd></div>
          <div><dt>Models</dt><dd>38,620</dd></div>
        </dl>
        <p class="pl-card-tags"><span class="pl-pill pl-pill-ok">Current</span> <span class="pl-pill pl-pill-info">Curated</span></p>
      </article>
      <article class="pl-card">
        <p class="pl-eyebrow">Tropical</p>
        <h3><a href="#pl-search">CML247 &mdash; NAM-1.0</a></h3>
        <p>A CIMMYT tropical line from the NAM founder set, annotated on the Helixer arm only.</p>
        <dl class="pl-card-facts">
          <div><dt>Length</dt><dd>2,151.6 Mb</dd></div>
          <div><dt>Contig N50</dt><dd>12.4 Mb</dd></div>
          <div><dt>Models</dt><dd>41,102</dd></div>
        </dl>
        <p class="pl-card-tags"><span class="pl-pill pl-pill-warn">Helixer only</span></p>
      </article>
      <article class="pl-card">
        <p class="pl-eyebrow">Wild relative</p>
        <h3><a href="#pl-search">TIL11 &mdash; parviglumis</a></h3>
        <p>A teosinte accession, included so that domestication comparisons have an outgroup with the same annotation treatment.</p>
        <dl class="pl-card-facts">
          <div><dt>Length</dt><dd>2,073.9 Mb</dd></div>
          <div><dt>Contig N50</dt><dd>8.1 Mb</dd></div>
          <div><dt>Models</dt><dd>36,447</dd></div>
        </dl>
        <p class="pl-card-tags"><span class="pl-pill pl-pill-info">Outgroup</span> <span class="pl-pill">Draft</span></p>
      </article>
    </div>

    <div class="pl-empty">
      <h3>No assemblies match that search</h3>
      <p>There are 52 assemblies. Clear the search and the species filters to see all of them.</p>
      <button class="pl-btn pl-btn-secondary" type="button">Clear search and filters</button>
    </div>
  </section>

  <!-- 5. Data table -------------------------------------------------------- -->
  <section id="pl-table" aria-labelledby="pl-table-t">
    <div class="pl-sechead">
      <div>
        <p class="pl-eyebrow">Evidence</p>
        <h2 id="pl-table-t">Scientific data table</h2>
      </div>
      <p class="pl-sechead-note">Numbers are right-aligned on tabular figures so columns compare down the page. Every figure on a page should also be readable as a table.</p>
    </div>
    <div class="pl-tablewrap">
      <table class="pl-table">
        <caption>Selected maize genome assemblies <span>Values are illustrative and are not drawn from the production database.</span></caption>
        <thead>
          <tr>
            <th scope="col">Genotype</th>
            <th scope="col">Assembly</th>
            <th scope="col" class="pl-num">Length (Mb)</th>
            <th scope="col" class="pl-num">Contig N50 (Mb)</th>
            <th scope="col" class="pl-num">Gene models</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr><th scope="row">B73</th><td>Zm-B73-REFERENCE-NAM-5.0</td><td class="pl-num">2,178.3</td><td class="pl-num">47.9</td><td class="pl-num">39,756</td><td><span class="pl-pill pl-pill-ok">Current</span></td></tr>
          <tr><th scope="row">Mo17</th><td>Zm-Mo17-REFERENCE-CAU-2.0</td><td class="pl-num">2,183.0</td><td class="pl-num">19.6</td><td class="pl-num">38,620</td><td><span class="pl-pill pl-pill-ok">Current</span></td></tr>
          <tr><th scope="row">CML247</th><td>Zm-CML247-REFERENCE-NAM-1.0</td><td class="pl-num">2,151.6</td><td class="pl-num">12.4</td><td class="pl-num">41,102</td><td><span class="pl-pill pl-pill-warn">Helixer only</span></td></tr>
          <tr><th scope="row">W22</th><td>Zm-W22-REFERENCE-NRGENE-2.0</td><td class="pl-num">2,133.4</td><td class="pl-num">6.8</td><td class="pl-num">37,891</td><td><span class="pl-pill">Superseded</span></td></tr>
          <tr><th scope="row">TIL11</th><td>Zx-TIL11-REFERENCE-PANAND-1.0</td><td class="pl-num">2,073.9</td><td class="pl-num">8.1</td><td class="pl-num">36,447</td><td><span class="pl-pill pl-pill-info">Outgroup</span></td></tr>
        </tbody>
        <tfoot>
          <tr><th scope="row">Median</th><td>5 assemblies</td><td class="pl-num">2,151.6</td><td class="pl-num">12.4</td><td class="pl-num">38,620</td><td></td></tr>
        </tfoot>
      </table>
    </div>
    <p class="pl-fine">Contig N50 is reported before scaffolding. A dash means the submitter did not report the value; it is never shown as zero.</p>
  </section>

  <!-- 6. Chart ------------------------------------------------------------- -->
  <section id="pl-chart" aria-labelledby="pl-chart-t">
    <div class="pl-sechead">
      <div>
        <p class="pl-eyebrow">Figures</p>
        <h2 id="pl-chart-t">Chart treatment</h2>
      </div>
      <p class="pl-sechead-note">A style has to say what a chart looks like: the plot ground, the grid, the series colours, and how a caption sits under it.</p>
    </div>
    <figure class="pl-figure">
      <div class="pl-chart">
        <div class="pl-bars">
          <div class="pl-bar" style="--v:100"><span class="pl-bar-fill"></span><span class="pl-bar-label">B73</span><span class="pl-bar-value">2,178</span></div>
          <div class="pl-bar" style="--v:100"><span class="pl-bar-fill"></span><span class="pl-bar-label">Mo17</span><span class="pl-bar-value">2,183</span></div>
          <div class="pl-bar" style="--v:99"><span class="pl-bar-fill"></span><span class="pl-bar-label">CML247</span><span class="pl-bar-value">2,152</span></div>
          <div class="pl-bar" style="--v:98"><span class="pl-bar-fill"></span><span class="pl-bar-label">W22</span><span class="pl-bar-value">2,133</span></div>
          <div class="pl-bar" style="--v:95"><span class="pl-bar-fill is-alt"></span><span class="pl-bar-label">TIL11</span><span class="pl-bar-value">2,074</span></div>
        </div>
        <p class="pl-legend"><span class="pl-key"><i></i>Zea mays</span> <span class="pl-key"><i class="is-alt"></i>Outgroup</span></p>
      </div>
      <figcaption><b>Figure 1.</b> Assembly length by genotype, in megabases. The axis is clipped at 2,000 Mb; the full range is stated in the table above so the clip cannot mislead.</figcaption>
    </figure>
  </section>

  <!-- 7. States ------------------------------------------------------------ -->
  <section id="pl-states" aria-labelledby="pl-states-t">
    <div class="pl-sechead">
      <div>
        <p class="pl-eyebrow">Feedback</p>
        <h2 id="pl-states-t">Messages and states</h2>
      </div>
      <p class="pl-sechead-note">Four tones, each legible without its colour. Colour is confirmation, never the only signal.</p>
    </div>
    <div class="pl-stack">
      <div class="pl-msg pl-msg-ok" role="status"><b>Saved.</b> Your stock request was recorded and the curator has been notified.</div>
      <div class="pl-msg pl-msg-info" role="status"><b>Partial record.</b> The GRIN service did not answer in time, so accession details are missing from this page. Everything else is complete.</div>
      <div class="pl-msg pl-msg-warn" role="status"><b>Truncated.</b> This locus has 5,412 linked references; the first 500 are shown. Narrow the query or download the full set.</div>
      <div class="pl-msg pl-msg-error" role="alert"><b>No such identifier.</b> Nothing matches <code>Zm00001eb999999</code> in this annotation. It may belong to an earlier release.</div>
      <p class="pl-loading"><span class="pl-spinner" aria-hidden="true"></span> Loading pan-gene assignments…</p>
    </div>
  </section>

  <!-- 8. Buttons and pills -------------------------------------------------- -->
  <section id="pl-buttons" aria-labelledby="pl-buttons-t">
    <div class="pl-sechead">
      <div>
        <p class="pl-eyebrow">Controls</p>
        <h2 id="pl-buttons-t">Buttons and labels</h2>
      </div>
      <p class="pl-sechead-note">Three button weights and one disabled state. Pills carry status, never actions.</p>
    </div>
    <p class="pl-row">
      <button class="pl-btn pl-btn-primary" type="button">Download dataset</button>
      <button class="pl-btn pl-btn-secondary" type="button">View methods</button>
      <button class="pl-btn pl-btn-quiet" type="button">Cancel</button>
      <button class="pl-btn pl-btn-primary" type="button" disabled>Unavailable</button>
    </p>
    <p class="pl-row">
      <span class="pl-pill pl-pill-ok">Current</span>
      <span class="pl-pill pl-pill-info">Curated</span>
      <span class="pl-pill pl-pill-warn">Helixer only</span>
      <span class="pl-pill pl-pill-error">Withdrawn</span>
      <span class="pl-pill">Superseded</span>
    </p>
  </section>

  <!-- 9. Forms -------------------------------------------------------------- -->
  <section id="pl-forms" aria-labelledby="pl-forms-t">
    <div class="pl-sechead">
      <div>
        <p class="pl-eyebrow">Input</p>
        <h2 id="pl-forms-t">Form controls</h2>
      </div>
      <p class="pl-sechead-note">Labels sit above their control, hints above the input rather than below it, and an error names the field and the fix.</p>
    </div>
    <form class="pl-form" onsubmit="return false">
      <div class="pl-field">
        <label class="pl-label" for="pl-f1">Locus symbol <span class="pl-req">required</span></label>
        <p class="pl-hint" id="pl-f1-hint">Published symbol, not a gene model identifier</p>
        <input class="pl-input" id="pl-f1" type="text" value="dek12" aria-describedby="pl-f1-hint">
      </div>
      <div class="pl-field">
        <label class="pl-label" for="pl-f2">Annotation arm</label>
        <p class="pl-hint" id="pl-f2-hint">Curated and Helixer are never pooled</p>
        <select class="pl-input" id="pl-f2" aria-describedby="pl-f2-hint">
          <option>Curated gene models</option>
          <option>Helixer gene models</option>
        </select>
      </div>
      <div class="pl-field pl-field-wide">
        <label class="pl-label" for="pl-f3">Notes for the curator</label>
        <textarea class="pl-input" id="pl-f3" rows="3">Phenotype scored on three biological replicates.</textarea>
      </div>
      <div class="pl-field pl-field-bad">
        <label class="pl-label" for="pl-f4">Chromosome coordinate</label>
        <input class="pl-input" id="pl-f4" type="text" value="chr11:4,201" aria-describedby="pl-f4-err" aria-invalid="true">
        <p class="pl-err" id="pl-f4-err">Maize has ten chromosomes. Use chr1 through chr10.</p>
      </div>
      <fieldset class="pl-fieldset">
        <legend>Include in export</legend>
        <label class="pl-check"><input type="checkbox" checked> Coordinates</label>
        <label class="pl-check"><input type="checkbox" checked> Sequence</label>
        <label class="pl-check"><input type="checkbox"> Expression</label>
      </fieldset>
      <p class="pl-formactions">
        <button class="pl-btn pl-btn-primary" type="submit">Run search</button>
        <button class="pl-btn pl-btn-quiet" type="reset">Clear</button>
      </p>
    </form>
  </section>

  <!-- 10. Tokens ------------------------------------------------------------ -->
  <section id="pl-tokens" aria-labelledby="pl-tokens-t">
    <div class="pl-sechead">
      <div>
        <p class="pl-eyebrow">Foundations</p>
        <h2 id="pl-tokens-t">Design tokens</h2>
      </div>
      <p class="pl-sechead-note">The whole style reduces to these. Swapping this block is what makes the other four pages different from this one.</p>
    </div>
    <div class="pl-swatches">
      <div class="pl-swatch"><span style="background:var(--pl-accent)"></span><b>Accent</b><code>--pl-accent</code></div>
      <div class="pl-swatch"><span style="background:var(--pl-accent-2)"></span><b>Secondary</b><code>--pl-accent-2</code></div>
      <div class="pl-swatch"><span style="background:var(--pl-ink)"></span><b>Ink</b><code>--pl-ink</code></div>
      <div class="pl-swatch"><span style="background:var(--pl-muted)"></span><b>Muted</b><code>--pl-muted</code></div>
      <div class="pl-swatch"><span style="background:var(--pl-surface)"></span><b>Surface</b><code>--pl-surface</code></div>
      <div class="pl-swatch"><span style="background:var(--pl-surface-2)"></span><b>Sunken</b><code>--pl-surface-2</code></div>
      <div class="pl-swatch"><span style="background:var(--pl-ok)"></span><b>Success</b><code>--pl-ok</code></div>
      <div class="pl-swatch"><span style="background:var(--pl-warn)"></span><b>Warning</b><code>--pl-warn</code></div>
      <div class="pl-swatch"><span style="background:var(--pl-error)"></span><b>Error</b><code>--pl-error</code></div>
    </div>
    <div class="pl-typespec">
      <p class="pl-type-row"><span class="pl-type-name">Display</span><span class="pl-type-sample pl-t-display">Zea mays</span></p>
      <p class="pl-type-row"><span class="pl-type-name">Heading</span><span class="pl-type-sample pl-t-h2">Genome assemblies</span></p>
      <p class="pl-type-row"><span class="pl-type-name">Body</span><span class="pl-type-sample pl-t-body">The reference assembly and the coordinate system every track is projected onto.</span></p>
      <p class="pl-type-row"><span class="pl-type-name">Small</span><span class="pl-type-sample pl-t-small">Contig N50 is reported before scaffolding.</span></p>
      <p class="pl-type-row"><span class="pl-type-name">Mono</span><span class="pl-type-sample pl-t-mono">Zm00001eb000010</span></p>
    </div>
  </section>

</main>

<footer class="pl-footer">
  <div class="pl-shell pl-footer-inner">
    <p><strong>MaizeGDB</strong> &mdash; pattern library <?php echo $PL; ?> of 5, &ldquo;<?php echo e($me['name']); ?>&rdquo;. A design study, not a live page.</p>
    <p class="pl-fine">USDA-ARS and Iowa State University. Same markup as the other four; only the stylesheet differs.</p>
  </div>
</footer>

</body>
</html>
