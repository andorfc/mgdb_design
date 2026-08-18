<?php
/* file: page_header_lib.php
 *
 * purpose: render the page header ("header bubble") — a rounded card whose
 *          left portion is a solid wheat field carrying the page title,
 *          blending on the right into a photograph.
 *
 *          The styling lives in /css/mgdb-page-header.css. This file only
 *          builds the markup and turns the options a page cares about into the
 *          custom properties that stylesheet reads, so a page never has to
 *          write CSS or remember the class names.
 *
 * usage:
 *   include_once('./include/page_header_lib.php');
 *
 *   $header = mgdb_page_header(array(
 *     'title' => 'Shared interface components',
 *     'lede'  => 'The reusable building blocks for modernized MaizeGDB pages.',
 *     'body'  => 'This reference renders every component defined in the shared stylesheet.',
 *     'photo' => '/images/headers/cornfield-sample.jpg',
 *   ));
 *
 * Everything else has a default and is only emitted when it differs, so the
 * inline style stays short and the stylesheet stays the source of truth.
 *
 * The markup is returned rather than printed so it can be handed to a Bauplan
 * template with ->replace(). That is also why it exists at all: putting this
 * markup in a .bau file would mean escaping every literal parenthesis in the
 * url() and the gradient, which is exactly the kind of thing that breaks
 * quietly.
 */

/* Defaults. A value equal to its default is not written to the element. */
function mgdb_page_header_defaults() {
  return array(
    'title'          => '',
    'lede'           => '',
    'body'           => '',
    'photo'          => '',          /* path or absolute URL; '' means tint only */
    'photo_alt'      => '',          /* the photo is decorative by default */
    'photo_position' => 'center',
    'photo_filter'   => 'saturate(1.12) contrast(1.08)',
    'logo'           => '',          /* set to use the logo-column variant */
    'logo_alt'       => '',
    'fade_start'     => '60%',
    'fade_end'       => '86%',
    'text_width'     => '60%',
    'min_height'     => '210px',
    'title_size'     => '38px',
    'title_wrap'     => 'nowrap',
    'tint_rgb'       => '246, 232, 203',
    'heading_level'  => 'h1',
    'id'             => '',
    'class'          => '',
  );
}

function mgdb_page_header_esc($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* A percentage, or nothing.
 *
 * These land inside a CSS gradient and a max-width, so anything that is not a
 * plain percentage is dropped rather than escaped through. An unchecked value
 * here would let page content close the style attribute. */
function mgdb_page_header_pct($value, $fallback) {
  return preg_match('/^\d{1,3}(\.\d+)?%$/', (string)$value) ? $value : $fallback;
}

/* A CSS length: px, rem, em, vh, or a bare 0. Same reasoning as above. */
function mgdb_page_header_len($value, $fallback) {
  return preg_match('/^(0|\d{1,4}(\.\d+)?(px|rem|em|vh))$/', (string)$value) ? $value : $fallback;
}

/* url() is built here rather than taken from the caller, so a photo path
 * cannot carry its own parenthesis or quote out of the attribute. */
function mgdb_page_header_photo_url($photo) {
  $photo = trim((string)$photo);
  if ($photo === '') { return ''; }
  if (!preg_match('#^(/|https?://)[^\s\'"()<>\\\\]+$#', $photo)) { return ''; }
  return "url('" . mgdb_page_header_esc($photo) . "')";
}

function mgdb_page_header($options = array()) {
  $defaults = mgdb_page_header_defaults();
  $o = array_merge($defaults, is_array($options) ? $options : array());

  $o['fade_start'] = mgdb_page_header_pct($o['fade_start'], $defaults['fade_start']);
  $o['fade_end']   = mgdb_page_header_pct($o['fade_end'],   $defaults['fade_end']);
  $o['text_width'] = mgdb_page_header_pct($o['text_width'], $defaults['text_width']);
  $o['min_height'] = mgdb_page_header_len($o['min_height'], $defaults['min_height']);
  $o['title_size'] = mgdb_page_header_len($o['title_size'], $defaults['title_size']);

  /* Text on the photo cannot be read. Rather than render a header that fails
     contrast, the text column is pulled back to where the tint is still
     solid. The caller is told, because silently ignoring what they asked for
     is how a layout bug becomes a mystery. */
  $warnings = array();
  $width = (float)$o['text_width'];
  $solid = (float)$o['fade_start'];
  if ($width > $solid) {
    $warnings[] = sprintf(
      'text_width %s exceeds fade_start %s; text would sit on the photo. Clamped to %s.',
      $o['text_width'], $o['fade_start'], $o['fade_start']);
    $o['text_width'] = $o['fade_start'];
  }
  if ((float)$o['fade_end'] <= (float)$o['fade_start']) {
    $warnings[] = sprintf('fade_end %s is not past fade_start %s; using the defaults.',
      $o['fade_end'], $o['fade_start']);
    $o['fade_start'] = $defaults['fade_start'];
    $o['fade_end']   = $defaults['fade_end'];
  }
  foreach ($warnings as $warning) {
    if (function_exists('logMessage')) { logMessage('mgdb_page_header: ' . $warning); }
  }

  /* Only what differs from the stylesheet is written to the element. */
  $vars = array();
  $photo_url = mgdb_page_header_photo_url($o['photo']);
  if ($photo_url !== '')                                { $vars['--mgdb-header-photo'] = $photo_url; }
  if ($o['photo_position'] !== $defaults['photo_position']) { $vars['--mgdb-header-photo-position'] = $o['photo_position']; }
  if ($o['photo_filter']   !== $defaults['photo_filter'])   { $vars['--mgdb-header-photo-filter']   = $o['photo_filter']; }
  if ($o['fade_start'] !== $defaults['fade_start'])     { $vars['--mgdb-header-fade-start'] = $o['fade_start']; }
  if ($o['fade_end']   !== $defaults['fade_end'])       { $vars['--mgdb-header-fade-end']   = $o['fade_end']; }
  if ($o['text_width'] !== $defaults['text_width'])     { $vars['--mgdb-header-text-width'] = $o['text_width']; }
  if ($o['min_height'] !== $defaults['min_height'])     { $vars['--mgdb-header-min-height'] = $o['min_height']; }
  if ($o['title_size'] !== $defaults['title_size'])     { $vars['--mgdb-header-title-size'] = $o['title_size']; }
  if ($o['title_wrap'] !== $defaults['title_wrap'])     { $vars['--mgdb-header-title-wrap'] = $o['title_wrap']; }
  if ($o['tint_rgb']   !== $defaults['tint_rgb'])       { $vars['--mgdb-header-tint-rgb']   = $o['tint_rgb']; }

  $style = '';
  foreach ($vars as $name => $value) {
    /* A custom property value can contain a colon or a parenthesis but never a
       quote or a semicolon once it has been through the validators above. */
    $style .= $name . ':' . str_replace(array(';', '"'), '', $value) . ';';
  }

  $classes = 'mgdb-page-header';
  if ($o['logo'] !== '')  { $classes .= ' mgdb-page-header--logo'; }
  if ($o['class'] !== '') { $classes .= ' ' . $o['class']; }

  $level = in_array($o['heading_level'], array('h1', 'h2', 'h3'), true) ? $o['heading_level'] : 'h1';

  $html  = '<header class="' . mgdb_page_header_esc($classes) . '"';
  if ($o['id'] !== '') { $html .= ' id="' . mgdb_page_header_esc($o['id']) . '"'; }
  if ($style !== '')   { $html .= ' style="' . mgdb_page_header_esc($style) . '"'; }
  $html .= '>';

  if ($o['logo'] !== '') {
    $logo_src = trim($o['logo']);
    $html .= '<div class="mgdb-page-header__logo">'
           . '<img src="' . mgdb_page_header_esc($logo_src) . '"'
           . ' alt="' . mgdb_page_header_esc($o['logo_alt']) . '"'
           . ($o['logo_alt'] === '' ? ' aria-hidden="true"' : '')
           . ' width="104" height="104">'
           . '</div>';
  }

  $html .= '<div class="mgdb-page-header__body">';
  if ($o['title'] !== '') {
    $html .= '<' . $level . ' class="mgdb-page-header__title">' . mgdb_page_header_esc($o['title']) . '</' . $level . '>';
  }
  if ($o['lede'] !== '') {
    $html .= '<p class="mgdb-page-header__lede">' . mgdb_page_header_esc($o['lede']) . '</p>';
  }
  if ($o['body'] !== '') {
    $html .= '<p class="mgdb-page-header__text">' . mgdb_page_header_esc($o['body']) . '</p>';
  }
  $html .= '</div></header>';

  return $html;
}
?>
