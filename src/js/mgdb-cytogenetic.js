(function () {
  'use strict';

  function syncToggle(button) {
    var indicator = document.getElementById(button.getAttribute('data-cyt-indicator'));
    var expanded = indicator && indicator.textContent.trim() === '-';
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  }

  function runToggle(button) {
    var indicatorId = button.getAttribute('data-cyt-indicator');
    var targetId = button.getAttribute('data-cyt-target');
    var term = button.getAttribute('data-cyt-term');
    var mode = button.getAttribute('data-cyt-mode');
    var target = document.getElementById(targetId);

    if (!target) return;

    if (mode === 'stock' && typeof window.toggle_display_adv === 'function') {
      window.toggle_display_adv(indicatorId, targetId, 'stock', term);
    } else if (mode === 'locus' && typeof window.toggle_display === 'function') {
      window.toggle_display(indicatorId, targetId, 'locus', term);
    }

    syncToggle(button);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var buttons = document.querySelectorAll('[data-cyt-mode]');
    if (!buttons.length) return;

    buttons.forEach(function (button) {
      syncToggle(button);
      button.addEventListener('click', function () {
        runToggle(button);
      });
    });

    var hash = window.location.hash.slice(1);
    if (!hash) return;

    var targetCard = document.getElementById(hash);
    var hashButton = targetCard && targetCard.querySelector('[data-cyt-mode]');
    if (hashButton && hashButton.getAttribute('aria-expanded') !== 'true') {
      hashButton.click();
    }
  });
})();
