/* mgdb-videos.js -- /community/videos and /videos
 *
 * Each card ships as a poster served from this host with a play button over
 * it. The Vimeo player is created only when a reader presses that button, so a
 * visit that watches nothing makes no third-party request at all -- the page
 * this replaced opened ten of them on load, whether or not anyone scrolled to
 * the videos.
 *
 * Bauplan::includeScript emits into <head>, so nothing below may touch the
 * document until it has been parsed.
 */
(function () {
  'use strict';

  /* Vimeo's player parameters:
       dnt=1        Do Not Track -- no cookies and no session recording
       autoplay=1   the reader has just pressed play, so it is expected
       title/byline/portrait=0   the card already says all three
     `title` on the iframe is what a screen reader announces for the frame. */
  function playerFor(id, title) {
    var frame = document.createElement('iframe');
    frame.src = 'https://player.vimeo.com/video/' + encodeURIComponent(id)
      + '?autoplay=1&dnt=1&title=0&byline=0&portrait=0';
    frame.title = title;
    frame.allow = 'autoplay; fullscreen; picture-in-picture';
    frame.setAttribute('allowfullscreen', '');
    frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    return frame;
  }

  function initPlayers() {
    Array.prototype.forEach.call(document.querySelectorAll('.videos-play'), function (button) {
      button.addEventListener('click', function () {
        var id = button.getAttribute('data-video-id');
        var title = button.getAttribute('data-video-title') || 'Video';
        var frame = button.parentNode;
        if (!id || !frame) { return; }

        var player = playerFor(id, title);
        frame.replaceChild(player, button);
        /* The player is now the only thing in the frame and the control the
           reader just used is gone, so focus has nowhere to go unless it is
           moved. */
        player.focus({ preventScroll: true });
      });
    });
  }

  /* ── Section tabs ──────────────────────────────────────────────────────── */

  /* The trigger line is read back from the section's own scroll-margin-top, so
     the line the spy marks a section at and the offset a click parks it at
     agree by construction. A hardcoded value disagrees with the stylesheet the
     moment the bar wraps, and then clicking a tab marks the section above the
     one it jumped to. */
  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) { return; }

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href') || '';
      if (href.charAt(0) !== '#') { return; }
      var section = document.getElementById(href.slice(1));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { return; }

    var heldUntilScroll = null;
    var heldAtY = 0;

    function mark(section) {
      pairs.forEach(function (pair) {
        var current = pair.section === section;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    function triggerLine() {
      var bar = document.querySelector('.mgdb-section-tabs');
      var barHeight = bar ? bar.getBoundingClientRect().height : 0;
      var margin = parseFloat(window.getComputedStyle(pairs[0].section).scrollMarginTop) || 0;
      return Math.max(barHeight + 8, margin + 4);
    }

    function update() {
      if (heldUntilScroll) {
        if (Math.abs(window.scrollY - heldAtY) < 4) { return; }
        heldUntilScroll = null;
      }
      var line = triggerLine();
      var current = pairs[0];
      pairs.forEach(function (pair) {
        if (pair.section.hasAttribute('hidden')) { return; }
        if (pair.section.getBoundingClientRect().top <= line) { current = pair; }
      });
      /* Without a bottom-of-page case the last section never highlights: it is
         shorter than the viewport, so its top never reaches the line. */
      if ((window.innerHeight + window.scrollY) >= (document.documentElement.scrollHeight - 2)) {
        current = pairs[pairs.length - 1];
      }
      if (current) { mark(current.section); }
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () {
        mark(pair.section);
        heldUntilScroll = pair.section;
        heldAtY = window.scrollY;
      });
    });

    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);

    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(function () { update(); },
        { rootMargin: '-20% 0px -60% 0px' });
      pairs.forEach(function (pair) { observer.observe(pair.section); });
    }

    /* Posters are lazily loaded, so a card's height is not final until its
       image arrives and every section below it moves when one does. */
    Array.prototype.forEach.call(document.querySelectorAll('.videos-frame img'), function (img) {
      if (!img.complete) { img.addEventListener('load', update, { once: true }); }
    });

    /* Arriving at /community/videos#videos-pollination, the browser's fragment
       scroll lands outside the listeners registered above, so the bar would
       mark the first tab while the reader is already further down. */
    if (document.readyState !== 'complete') {
      window.addEventListener('load', function () { window.setTimeout(update, 0); });
    }

    update();
  }

  function init() {
    initPlayers();
    buildTabs();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
