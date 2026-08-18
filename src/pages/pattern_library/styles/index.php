<?php
/*
 * Chooser for the five alternative design languages.
 *
 * Deliberately unstyled beyond the base sheet: it is the neutral ground the
 * five are compared from, so it must not argue for any of them.
 */
$PL_STYLES = array(
  1 => array('Journal',    'Editorial, printed-page typography', 'Serif display and body, hairline rules, one ink and one accent. Reads like a methods section. Highest-effort typography, lowest visual noise.'),
  2 => array('Console',    'Dense, monospaced, built for reading data', 'Monospace throughout, square corners, borders instead of shadows, 13px base. Fits roughly a third more table on a screen than the current site.'),
  3 => array('Grid',       'Swiss typographic grid, high type contrast', 'Heavy rules, no radii, no shadows, enormous heading-to-body contrast, one signal colour. Neutral enough to survive a decade.'),
  4 => array('Prairie',    'Open, rounded, light on its feet', 'Generous whitespace, 16px radii, soft tinted surfaces, maize gold as the working accent. The friendliest of the five and the least dense.'),
  5 => array('Instrument', 'Dark ground, luminous data', 'Near-black panels, luminous accents, monospaced numerals. Charts and sequence views sit on it well; long prose is harder work.'),
);
?>
<!DOCTYPE html>
<html lang="en" data-style="0">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Five alternative design languages | MaizeGDB</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/css/pattern-style-base.css?v=<?php echo @filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/pattern-style-base.css'); ?>">
<link rel="stylesheet" href="/css/pattern-style-index.css?v=<?php echo @filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/pattern-style-index.css'); ?>">
</head>
<body>
<nav class="pl-switch" aria-label="Choose a style">
  <span class="pl-switch-label">Style</span>
  <?php foreach ($PL_STYLES as $n => $s): ?>
    <a class="pl-switch-link" href="/pattern_library/styles/<?php echo $n; ?>/"><?php echo $n . '. ' . htmlspecialchars($s[0]); ?></a>
  <?php endforeach; ?>
  <span class="pl-switch-sep" aria-hidden="true"></span>
  <a class="pl-switch-link is-current" href="/pattern_library/styles/" aria-current="page">All five</a>
  <a class="pl-switch-link" href="/pattern_library/">Current site</a>
</nav>

<main class="pl-shell" id="pl-main">
  <h1>Five alternative design languages</h1>
  <p class="pl-lede">Each renders the same pattern library from the same markup. The only difference between them is one stylesheet, so what you are comparing is the design language and not the content.</p>
  <p class="pl-fine">These are design studies. Nothing here is applied to any page on the site, and the current library at <a href="/pattern_library/">/pattern_library/</a> is untouched.</p>

  <ol class="pl-choices">
    <?php foreach ($PL_STYLES as $n => $s): ?>
    <li class="pl-choice">
      <a class="pl-choice-link" href="/pattern_library/styles/<?php echo $n; ?>/">
        <span class="pl-choice-n"><?php echo $n; ?></span>
        <span class="pl-choice-body">
          <strong><?php echo htmlspecialchars($s[0]); ?></strong>
          <em><?php echo htmlspecialchars($s[1]); ?></em>
          <span><?php echo htmlspecialchars($s[2]); ?></span>
        </span>
      </a>
    </li>
    <?php endforeach; ?>
  </ol>

  <h2>What to look at</h2>
  <ul class="pl-checklist">
    <li><strong>The data table.</strong> It is the component this site lives or dies on. Scroll to it in each and see which one you would rather read 200 rows in.</li>
    <li><strong>Density.</strong> How much fits above the fold differs by roughly a third between 2 and 4.</li>
    <li><strong>The prose block.</strong> Nomenclature, methods, and help pages are long-form. Two of these are built for that and three are not.</li>
    <li><strong>The metric row and the pills.</strong> Every record and search page in the redesign uses both.</li>
    <li><strong>Whether it still reads as MaizeGDB.</strong> All five draw from the existing brand colours; they disagree about how loudly to use them.</li>
  </ul>
</main>
</body>
</html>
