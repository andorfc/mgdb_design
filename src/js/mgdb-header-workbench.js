/* ==========================================================================
   Page header workbench (/pattern_library/header/)
   --------------------------------------------------------------------------
   Drives the live preview. The preview element is the real component rendered
   by mgdb_page_header(), so everything here does is set the same custom
   properties the PHP would set, and write the text into the same three nodes.
   Nothing is re-rendered from a template: what is on screen is the component.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var DEFAULTS = {
    fadeStart: 60, fadeEnd: 86, textWidth: 60,
    minHeight: 210, titleSize: 38, wrap: false,
    position: 'center',
    photo: '/images/headers/cornfield-sample.jpg',
    title: 'Shared interface components',
    lede: 'The reusable building blocks for modernized MaizeGDB pages.',
    body: 'This reference renders every component defined in the shared stylesheet so that spacing, contrast, keyboard behavior, and responsive breakpoints can be verified in one place before a pattern is applied to a production page.'
  };

  var $ = function (id) { return document.getElementById(id); };

  function init() {
    var header = $('wb-preview');
    if (!header) { return; }

    var el = {
      stage: $('wb-stage'),
      title: header.querySelector('.mgdb-page-header__title'),
      lede: header.querySelector('.mgdb-page-header__lede'),
      body: header.querySelector('.mgdb-page-header__text'),
      flag: $('wb-flag'),
      snippet: $('wb-snippet')
    };

    var input = {
      title: $('wb-title'), lede: $('wb-lede'), body: $('wb-body'),
      photo: $('wb-photo'), position: $('wb-position'),
      fadeStart: $('wb-fade-start'), fadeEnd: $('wb-fade-end'),
      textWidth: $('wb-text-width'), minHeight: $('wb-min-height'),
      titleSize: $('wb-title-size'), wrap: $('wb-wrap'), guides: $('wb-guides')
    };

    /* ---- apply -------------------------------------------------------- */

    function num(control) { return parseInt(control.value, 10); }

    function apply() {
      var fadeStart = num(input.fadeStart);
      var fadeEnd   = num(input.fadeEnd);
      var textWidth = num(input.textWidth);

      /* The two constraints the component cannot render its way out of. The
         controls are corrected rather than just warned about, so the preview
         is never showing something the PHP would refuse to produce. */
      var notes = [];
      if (fadeEnd <= fadeStart) {
        fadeEnd = Math.min(100, fadeStart + 5);
        input.fadeEnd.value = fadeEnd;
        notes.push('Fade end has to sit past fade start; moved it to ' + fadeEnd + '%.');
      }
      if (textWidth > fadeStart) {
        textWidth = fadeStart;
        input.textWidth.value = textWidth;
        notes.push('Text column pulled back to ' + fadeStart + '%: past the fade start it would sit on the photograph.');
      }

      header.style.setProperty('--mgdb-header-fade-start', fadeStart + '%');
      header.style.setProperty('--mgdb-header-fade-end', fadeEnd + '%');
      header.style.setProperty('--mgdb-header-text-width', textWidth + '%');
      header.style.setProperty('--mgdb-header-min-height', num(input.minHeight) + 'px');
      header.style.setProperty('--mgdb-header-title-size', num(input.titleSize) + 'px');
      header.style.setProperty('--mgdb-header-title-wrap', input.wrap.checked ? 'normal' : 'nowrap');
      header.style.setProperty('--mgdb-header-photo-position', input.position.value);

      var photo = input.photo.value.trim();
      header.style.setProperty('--mgdb-header-photo', photo ? "url('" + photo + "')" : 'none');

      el.title.textContent = input.title.value;
      el.lede.textContent = input.lede.value;
      el.body.textContent = input.body.value;
      el.title.hidden = input.title.value === '';
      el.lede.hidden = input.lede.value === '';
      el.body.hidden = input.body.value === '';

      $('wb-fs-v').textContent = fadeStart + '%';
      $('wb-fe-v').textContent = fadeEnd + '%';
      $('wb-tw-v').textContent = textWidth + '%';
      $('wb-mh-v').textContent = num(input.minHeight) + 'px';
      $('wb-ts-v').textContent = num(input.titleSize) + 'px';
      $('wb-covered').textContent = fadeStart;

      header.classList.toggle('wb-guides-on', input.guides.checked);
      header.style.setProperty('--wb-guide-fade', fadeStart + '%');
      header.style.setProperty('--wb-guide-text', textWidth + '%');

      /* A title set not to wrap will run under the photo and out of the card
         rather than pushing anything, so it has to be measured, not assumed.
         A zero-width column means the header is not laid out yet — measuring
         it then reports every title as too wide, which is how a warning stops
         being worth reading. */
      if (!input.wrap.checked && el.title.clientWidth > 0
          && el.title.scrollWidth > el.title.clientWidth + 1) {
        notes.push('The title is wider than its column: it is being clipped. Reduce the title size or let it wrap.');
      }

      el.flag.hidden = notes.length === 0;
      el.flag.textContent = notes.join(' ');

      writeSnippet(fadeStart, fadeEnd, textWidth);
    }

    /* ---- the snippet ---------------------------------------------------- */

    /* Mirrors mgdb_page_header(): a value equal to the stylesheet default is
       not emitted, so what you paste is the shortest call that reproduces the
       preview. */
    function writeSnippet(fadeStart, fadeEnd, textWidth) {
      var lines = [];
      function put(key, value) { lines.push("  '" + key + "' => '" + String(value).replace(/'/g, "\\'") + "',"); }

      put('title', input.title.value);
      if (input.lede.value) { put('lede', input.lede.value); }
      if (input.body.value) { put('body', input.body.value); }
      if (input.photo.value.trim()) { put('photo', input.photo.value.trim()); }
      if (input.position.value !== DEFAULTS.position) { put('photo_position', input.position.value); }
      if (fadeStart !== DEFAULTS.fadeStart) { put('fade_start', fadeStart + '%'); }
      if (fadeEnd !== DEFAULTS.fadeEnd) { put('fade_end', fadeEnd + '%'); }
      if (textWidth !== DEFAULTS.textWidth) { put('text_width', textWidth + '%'); }
      if (num(input.minHeight) !== DEFAULTS.minHeight) { put('min_height', num(input.minHeight) + 'px'); }
      if (num(input.titleSize) !== DEFAULTS.titleSize) { put('title_size', num(input.titleSize) + 'px'); }
      if (input.wrap.checked) { put('title_wrap', 'normal'); }

      el.snippet.textContent =
        "mgdb_page_header(array(\n" + lines.join('\n') + "\n))";
    }

    /* ---- wiring --------------------------------------------------------- */

    Object.keys(input).forEach(function (key) {
      var control = input[key];
      if (!control) { return; }
      control.addEventListener('input', apply);
      control.addEventListener('change', apply);
    });

    /* Photo quick picks write into the same field the snippet reads, so the
       two can never disagree. */
    Array.prototype.forEach.call(document.querySelectorAll('.wb-pick'), function (pick) {
      pick.addEventListener('click', function () {
        input.photo.value = pick.getAttribute('data-photo') || '';
        Array.prototype.forEach.call(document.querySelectorAll('.wb-pick'), function (p) {
          p.classList.toggle('is-on', p === pick);
        });
        apply();
      });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.wb-widths button'), function (button) {
      button.addEventListener('click', function () {
        el.stage.style.width = button.getAttribute('data-width');
        Array.prototype.forEach.call(document.querySelectorAll('.wb-widths button'), function (b) {
          b.classList.toggle('is-on', b === button);
        });
        /* The narrow layout is a media query on the viewport, not on the card,
           so a narrowed stage shows the card at that width but still in the
           desktop layout. Say so rather than let it look like a bug. */
        var narrow = parseInt(button.getAttribute('data-width'), 10) <= 760;
        el.flag.hidden = !narrow;
        if (narrow) {
          el.flag.textContent = 'Below 760px the real page flips the photo to a top band. That is a '
            + 'viewport media query, so resize the browser to see it — narrowing the stage alone will not.';
        } else {
          apply();
        }
      });
    });

    $('wb-reset').addEventListener('click', function () {
      input.title.value = DEFAULTS.title;
      input.lede.value = DEFAULTS.lede;
      input.body.value = DEFAULTS.body;
      input.photo.value = DEFAULTS.photo;
      input.position.value = DEFAULTS.position;
      input.fadeStart.value = DEFAULTS.fadeStart;
      input.fadeEnd.value = DEFAULTS.fadeEnd;
      input.textWidth.value = DEFAULTS.textWidth;
      input.minHeight.value = DEFAULTS.minHeight;
      input.titleSize.value = DEFAULTS.titleSize;
      input.wrap.checked = DEFAULTS.wrap;
      apply();
    });

    $('wb-copy').addEventListener('click', function () {
      var text = el.snippet.textContent;
      var done = function (message) {
        $('wb-copied').textContent = message;
        window.setTimeout(function () { $('wb-copied').textContent = ''; }, 2500);
      };
      if (window.navigator.clipboard && window.isSecureContext) {
        window.navigator.clipboard.writeText(text).then(
          function () { done('Copied.'); },
          function () { done('Could not copy — select it instead.'); });
      } else {
        /* http on the dev instance has no clipboard API; select it so one
           keystroke finishes the job. */
        var range = document.createRange();
        range.selectNodeContents(el.snippet);
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        done('Selected — press Cmd-C.');
      }
    });

    apply();

    /* Layout can still move under us after this runs — fonts settle, the
       container query resolves. Measure again once, rather than trusting the
       first pass. */
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(apply); }
    window.addEventListener('resize', apply);
    window.setTimeout(apply, 250);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
