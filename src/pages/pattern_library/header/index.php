<?php
/*
 * Page header workbench.
 *
 * Renders the real component through the real function and the real
 * stylesheet, with controls for the things a page actually sets: the text, the
 * photo, and where the gradient starts. Nothing here is a mock — the preview
 * is mgdb_page_header() output, and the snippet at the bottom is the call that
 * produces what is on screen, ready to paste into a controller.
 *
 * Standalone rather than inside the modern shell so the header can be measured
 * without the site chrome around it. It is a workbench, not a page.
 */

$doc_root = $_SERVER['DOCUMENT_ROOT'];
include_once($doc_root . '/include/page_header_lib.php');

$defaults = mgdb_page_header_defaults();

/* The initial state is the design handoff's own example, so the workbench
   opens on the thing the design was signed off against. */
$initial = array(
  'title' => 'Shared interface components',
  'lede'  => 'The reusable building blocks for modernized MaizeGDB pages.',
  'body'  => 'This reference renders every component defined in the shared stylesheet so that spacing, contrast, keyboard behavior, and responsive breakpoints can be verified in one place before a pattern is applied to a production page.',
  'photo' => '/images/headers/cornfield-sample.jpg',
);

/* Photos offered as quick picks. The cornfield is the handoff's sample and is
   a placeholder: its provenance was never confirmed, so it must be replaced
   with a MaizeGDB/USDA-owned or public-domain image before this ships on a
   public page. The rest are already on this server. */
$photos = array(
  array('/images/headers/cornfield-sample.jpg', 'Cornfield (sample)', 'Placeholder — provenance unconfirmed'),
  array('/images/maize_meeting/idaho.jpg',      'Idaho',              'Already on the server'),
  array('/images/maize_meeting/allerton.jpg',   'Allerton',           'Already on the server'),
  array('',                                     'No photo',           'Tint only, no request'),
);

function wb_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Page header workbench | MaizeGDB</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/css/mgdb-page-header.css?v=<?php echo @filemtime($doc_root . '/css/mgdb-page-header.css'); ?>">
<link rel="stylesheet" href="/css/mgdb-header-workbench.css?v=<?php echo @filemtime($doc_root . '/css/mgdb-header-workbench.css'); ?>">
</head>
<body>

<header class="wb-bar">
  <strong>Page header workbench</strong>
  <span>Edit the text, move the fade, swap the photo. The preview is the real component.</span>
  <a href="/pattern_library/">Pattern library</a>
</header>

<main class="wb-shell">

  <!-- The preview. Width is driven by the stage so the 760px flip can be seen
       without resizing the browser. -->
  <section class="wb-stage-wrap" aria-labelledby="wb-preview-t">
    <div class="wb-stage-head">
      <h2 id="wb-preview-t">Preview</h2>
      <div class="wb-widths" role="group" aria-label="Preview width">
        <button type="button" data-width="100%" class="is-on">Full</button>
        <button type="button" data-width="1024px">1024</button>
        <button type="button" data-width="760px">760</button>
        <button type="button" data-width="375px">375</button>
      </div>
    </div>

    <div class="wb-stage" id="wb-stage">
      <?php
        echo mgdb_page_header(array(
          'title' => $initial['title'],
          'lede'  => $initial['lede'],
          'body'  => $initial['body'],
          'photo' => $initial['photo'],
          'id'    => 'wb-preview',
        ));
      ?>
    </div>

    <p class="wb-flag" id="wb-flag" hidden></p>
    <p class="wb-note">The tint is solid up to the fade start. Any text past that line sits on the
      photograph, where contrast is not guaranteed — an earlier iteration of this design put body
      text on lit foliage at about 1.5&#58;1. The text column is kept at or inside the fade start
      for that reason, in the stylesheet and in the PHP.</p>
  </section>

  <!-- Controls -->
  <section class="wb-controls" aria-labelledby="wb-controls-t">
    <h2 id="wb-controls-t" class="wb-sr">Controls</h2>

    <div class="wb-group">
      <h3>Text</h3>
      <label class="wb-field">
        <span>Title</span>
        <input type="text" id="wb-title" value="<?php echo wb_e($initial['title']); ?>">
        <em id="wb-title-note">Set to not wrap, by design. A longer title needs a smaller size or wrapping turned on.</em>
      </label>
      <label class="wb-field">
        <span>Lede</span>
        <input type="text" id="wb-lede" value="<?php echo wb_e($initial['lede']); ?>">
      </label>
      <label class="wb-field">
        <span>Body</span>
        <textarea id="wb-body" rows="4"><?php echo wb_e($initial['body']); ?></textarea>
      </label>
    </div>

    <div class="wb-group">
      <h3>Photo</h3>
      <div class="wb-picks" role="group" aria-label="Photo">
        <?php foreach ($photos as $i => $p): ?>
          <button type="button" class="wb-pick<?php echo $i === 0 ? ' is-on' : ''; ?>"
                  data-photo="<?php echo wb_e($p[0]); ?>">
            <span><?php echo wb_e($p[1]); ?></span>
            <em><?php echo wb_e($p[2]); ?></em>
          </button>
        <?php endforeach; ?>
      </div>
      <label class="wb-field">
        <span>Or a path of your own</span>
        <input type="text" id="wb-photo" value="<?php echo wb_e($initial['photo']); ?>"
               placeholder="/images/headers/your-photo.jpg">
        <em>A site path or an https URL. The interesting part of the picture has to sit right of
          centre — the left <span id="wb-covered">60</span>% is covered by the tint.</em>
      </label>
      <label class="wb-field wb-inline">
        <span>Focal point</span>
        <select id="wb-position">
          <option value="center">center</option>
          <option value="right center">right center</option>
          <option value="left center">left center</option>
          <option value="center top">center top</option>
          <option value="center bottom">center bottom</option>
        </select>
      </label>
    </div>

    <div class="wb-group">
      <h3>Gradient</h3>
      <label class="wb-field">
        <span>Fade start <b id="wb-fs-v">60%</b></span>
        <input type="range" id="wb-fade-start" min="20" max="95" step="1" value="60">
        <em>Tint fully solid up to here. This is the line the text must not cross.</em>
      </label>
      <label class="wb-field">
        <span>Fade end <b id="wb-fe-v">86%</b></span>
        <input type="range" id="wb-fade-end" min="25" max="100" step="1" value="86">
        <em>Photo fully clear past here. The two soft stops in between hold their shape as these move.</em>
      </label>
      <label class="wb-field">
        <span>Text column <b id="wb-tw-v">60%</b></span>
        <input type="range" id="wb-text-width" min="20" max="95" step="1" value="60">
      </label>
      <label class="wb-check"><input type="checkbox" id="wb-guides"> Show the fade start and text edge</label>
    </div>

    <div class="wb-group">
      <h3>Card</h3>
      <label class="wb-field">
        <span>Height <b id="wb-mh-v">210px</b></span>
        <input type="range" id="wb-min-height" min="140" max="360" step="2" value="210">
      </label>
      <label class="wb-field">
        <span>Title size <b id="wb-ts-v">38px</b></span>
        <input type="range" id="wb-title-size" min="20" max="56" step="1" value="38">
      </label>
      <label class="wb-check"><input type="checkbox" id="wb-wrap"> Let the title wrap</label>
      <p class="wb-reset"><button type="button" id="wb-reset">Reset to the handoff values</button></p>
    </div>

    <div class="wb-group">
      <h3>Use it</h3>
      <p class="wb-small">This is the call that produces exactly what is above. Only values that
        differ from the stylesheet's defaults appear.</p>
      <pre class="wb-code"><code id="wb-snippet"></code></pre>
      <p><button type="button" id="wb-copy" class="wb-copy">Copy</button> <span id="wb-copied" class="wb-small" role="status"></span></p>
    </div>
  </section>

  <section class="wb-docs">
    <h2>How a page uses it</h2>
    <pre class="wb-code"><code>include_once('./include/page_header_lib.php');
$bauplan-&gt;includeCss('/css/mgdb-page-header.css');

$body = $mgdb-&gt;get('body')-&gt;loadRemote(...);
$body-&gt;get('page-header')-&gt;replace(mgdb_page_header(array\(
  'title' =&gt; 'Stock search',
  'lede'  =&gt; 'Find maize genetic stocks.',
  'photo' =&gt; '/images/headers/cornfield-sample.jpg',
\)));</code></pre>
    <p class="wb-small">The markup is returned rather than printed so it can be handed to a
      template with <code>replace()</code>. That is also why the function exists: this markup in a
      <code>.bau</code> file would need every literal parenthesis in the <code>url()</code> and the
      gradient escaped, which is the kind of thing that breaks quietly.</p>
    <p class="wb-small"><b>Before this goes on a public page:</b> the cornfield photograph came
      from the design session with its provenance unconfirmed. Replace it with a MaizeGDB or USDA
      owned image, or a public-domain one — the USDA ARS Image Gallery is a good source — at about
      1600–2000px wide and under 300KB, with the subject right of centre.</p>
  </section>

</main>

<script src="/js/mgdb-header-workbench.js?v=<?php echo @filemtime($doc_root . '/js/mgdb-header-workbench.js'); ?>"></script>
</body>
</html>
