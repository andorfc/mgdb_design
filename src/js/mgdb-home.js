/* Homepage: switch the resource index between quick links and the list.

   Quick links are the default so the page opens the way the production site
   does. The choice is remembered per browser, because someone who prefers the
   dense list should not have to pick it on every visit.

   Both panels are in the HTML from the start, so the page works with no
   JavaScript at all -- it simply shows the quick links, which is the view the
   markup ships expanded. */

(function () {
  'use strict';

  var STORAGE_KEY = 'mgdb-home-view';
  var VALID = ['grid', 'list', 'tasks'];

  function read() {
    try {
      var stored = window.localStorage.getItem(STORAGE_KEY);
      return VALID.indexOf(stored) === -1 ? null : stored;
    } catch (error) {
      // Private browsing and blocked storage both throw here; the default view
      // is still correct, so there is nothing to recover from.
      return null;
    }
  }

  function write(view) {
    try {
      window.localStorage.setItem(STORAGE_KEY, view);
    } catch (error) { /* not worth surfacing */ }
  }

  function initialize() {
    var buttons = document.querySelectorAll('[data-home-view]');
    var panels = document.querySelectorAll('[data-home-panel]');
    if (!buttons.length || !panels.length) return;

    var heading = document.getElementById('home-index-title');

    function show(view, remember) {
      Array.prototype.forEach.call(panels, function (panel) {
        panel.hidden = panel.getAttribute('data-home-panel') !== view;
      });
      /* The heading names the view, so it has to follow it. "Key Resources"
         rather than "All resources" because that panel holds the same chosen
         twenty as the grid, not the whole site -- the link beneath the panel is
         what leads to all of them. */
      /* Every view is titled. Suppressing the heading on the tasks view was
         tried and reverted: without it the panel reads as though it lost its
         label, and the repetition with the toggle is the lesser cost. */
      var TITLES = {grid: 'Quick Links', list: 'Key Resources', tasks: 'Common Tasks'};
      if (heading) {
        heading.textContent = TITLES[view] || TITLES.grid;
        heading.hidden = false;
      }
      Array.prototype.forEach.call(buttons, function (button) {
        var active = button.getAttribute('data-home-view') === view;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      if (remember) write(view);
    }

    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('click', function () {
        show(button.getAttribute('data-home-view'), true);
      });
    });

    var stored = read();
    if (stored) show(stored, false);

    watchNews();
  }

  /* The news list renders three stories. When the newest leads with a picture
     the card grows tall enough to push the rail past the content beside it, so
     the third story is dropped in that case and three are kept otherwise.
     Measured rather than assumed: the trigger is the rendered height, so a long
     headline counts as well as an image.

     Re-run after each image loads -- a lead picture has no height until it
     decodes, and measuring before that would always see the short version. */
  var NEWS_MAX_HEIGHT = 420;

  function trimNews() {
    var list = document.querySelector('.mgdb-home-news');
    if (!list) return;
    var items = Array.prototype.slice.call(list.children);
    if (items.length < 3) return;

    items.forEach(function (li) { li.classList.remove('mgdb-home-news-trimmed'); });
    if (list.getBoundingClientRect().height > NEWS_MAX_HEIGHT) {
      items[items.length - 1].classList.add('mgdb-home-news-trimmed');
    }
  }

  function watchNews() {
    trimNews();
    Array.prototype.forEach.call(document.querySelectorAll('.mgdb-home-news img'), function (img) {
      if (!img.complete) img.addEventListener('load', trimNews, {once: true});
    });
    window.addEventListener('resize', trimNews);
  }

  /* Bauplan::includeScript() emits into <head>, so this runs while the document
     is still parsing and every query above would come back empty. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
})();
