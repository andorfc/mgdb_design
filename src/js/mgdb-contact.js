/* Contact directory (/contact).

   Every person is rendered server-side, so the directory is complete before
   this runs. Filtering only hides and shows what is already there. */

(function () {
  'use strict';

  function byId(id) { return document.getElementById(id); }

  function init() {
    if (!window.MGDB) { return; }

    var people = document.querySelectorAll('#contact-list .contact-person');
    if (!people.length) { return; }

    var groups = document.querySelectorAll('#contact-list .contact-group');

    var list = window.MGDB.filterList({
      items: people,
      input: byId('contact-query'),
      chips: document.querySelectorAll('.mgdb-chip[data-filter]'),
      count: byId('contact-count'),
      empty: byId('contact-empty'),
      reset: byId('contact-reset'),
      noun: 'team members',
      urlKeys: { query: 'q', filter: 'group' },
      // Cards carry data-group; filterList's default reads data-filter.
      filterOn: function (person, value) {
        return value === 'all' || person.getAttribute('data-group') === value;
      },
      onChange: function () {
        // Hide a group heading once every card beneath it is filtered out, so
        // the page never leaves an empty section behind.
        Array.prototype.forEach.call(groups, function (group) {
          var visible = 0;
          Array.prototype.forEach.call(group.querySelectorAll('.contact-person'), function (p) {
            if (!p.hidden) { visible += 1; }
          });
          group.hidden = visible === 0;
        });
      }
    });

    var emptyReset = byId('contact-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        var reset = byId('contact-reset');
        if (reset) { reset.click(); }
        else { list.refresh(); }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
