/* Maize Genetics Meeting (/maize_meeting/).

   Attendance and program charts, plus the searchable archive of past meeting
   websites. Data and behaviour ported from the MaizeGDB website repository.

   Adapted for the redesign: the charts are drawn through MGDB.chart() so they
   inherit the shared colour-blind-safe palette, lazy loading, resize handling,
   and text fallback, instead of calling Plotly directly. */

(function () {
  'use strict';

  var attendance = {
    labels: ['2000','2001','2002','2003','2004','2005','2006','2007','2008','2009','2010','2011','2012','2013','2014','2015','2016','2017','2018','2019','2020','2021','2022','2023','2024','2025','2026'],
    values: [409,398,494,507,432,552,503,514,505,473,438,579,560,613,584,608,571,677,456,644,670,721,543,485,520,486,412],
    locations: ["Coeur d'Alene, Idaho",'Lake Geneva, Wisconsin','Kissimmee, Florida','Lake Geneva, Wisconsin','Mexico City, Mexico','Lake Geneva, Wisconsin','Pacific Grove, California','St. Charles, Illinois','Washington, DC','St. Charles, Illinois','Riva del Garda, Italy','St. Charles, Illinois','Portland, Oregon','St. Charles, Illinois','Beijing, China','St. Charles, Illinois','Jacksonville, Florida','St. Louis, Missouri','Saint-Malo, France','St. Louis, Missouri','Virtual','Virtual','St. Louis, Missouri','St. Louis, Missouri','Raleigh, North Carolina','St. Louis, Missouri','Cologne, Germany']
  };

  var program = {
    labels: attendance.labels,
    talks: [39,25,36,27,43,28,33,36,46,35,33,35,34,35,29,33,34,32,33,40,26,32,30,30,30,28,30],
    posters: [161,168,192,210,185,248,258,224,241,244,261,297,312,354,221,341,376,416,268,393,106,193,219,296,318,303,273],
    lightning: [null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,40,21,30]
  };

  var archives = [
    {year:'2025', annual:67, location:'St. Louis, Missouri', url:'/mgc/maizemeeting/2025', image:'/images/maize_meeting/stlouis.png'},
    {year:'2024', annual:66, location:'Raleigh, North Carolina', url:'/mgc/maizemeeting/2024', image:'/images/maize_meeting/raleigh.png'},
    {year:'2023', annual:65, location:'St. Louis, Missouri', url:'/mgc/maizemeeting/2023', image:'/images/maize_meeting/stlouis.png'},
    {year:'2022', annual:64, location:'St. Louis, Missouri', url:'/mgc/maizemeeting/2022', image:'/images/maize_meeting/stlouis.png'},
    {year:'2021', annual:63, location:'Virtual', url:'/mgc/maizemeeting/2021', image:'/images/maize_meeting/virtual.jpg'},
    {year:'v2020', annual:62, location:'Virtual', url:'/maize_meeting/v2020', note:'virtual meeting · 670 attendees', image:'/images/maize_meeting/virtual.jpg'},
    {year:'2020', annual:62, location:'Keauhou Bay, Hawaii', url:'/maize_meeting/2020', note:'planned in-person meeting · canceled', canceled:true},
    {year:'2019', annual:61, location:'St. Louis, Missouri', url:'/maize_meeting/2019', image:'/images/maize_meeting/stlouis.png'},
    {year:'2018', annual:60, location:'Saint-Malo, France', url:'/maize_meeting/2018', image:'/images/maize_meeting/stmalo.png'},
    {year:'2017', annual:59, location:'St. Louis, Missouri', url:'/maize_meeting/2017', image:'/images/maize_meeting/stlouis.png'},
    {year:'2016', annual:58, location:'Jacksonville, Florida', url:'/maize_meeting/2016', image:'/images/maize_meeting/jacksonville.jpg'},
    {year:'2015', annual:57, location:'St. Charles, Illinois', url:'/maize_meeting/2015', image:'/images/maize_meeting/chicago.jpg'},
    {year:'2014', annual:56, location:'Beijing, China', url:'/maize_meeting/2014', image:'/images/maize_meeting/beijing.jpg'},
    {year:'2013', annual:55, location:'St. Charles, Illinois', url:'/maize_meeting/2013', image:'/images/maize_meeting/chicago.jpg'},
    {year:'2012', annual:54, location:'Portland, Oregon', url:'/maize_meeting/2012', image:'/images/maize_meeting/portland.jpg'},
    {year:'2011', annual:53, location:'St. Charles, Illinois', url:'/maize_meeting/2011', image:'/images/maize_meeting/chicago.jpg'},
    {year:'2010', annual:52, location:'Riva del Garda, Italy', url:'/maize_meeting/2010', image:'/images/maize_meeting/italy.jpg'},
    {year:'2009', annual:51, location:'St. Charles, Illinois', url:'/maize_meeting/2009', image:'/images/maize_meeting/chicago.jpg'},
    {year:'2008', annual:50, location:'Washington, DC', url:'/maize_meeting/2008', image:'/images/maize_meeting/dc.jpg'},
    {year:'2007', annual:49, location:'St. Charles, Illinois', url:'/maize_meeting/2007', image:'/images/maize_meeting/chicago.jpg'},
    {year:'2006', annual:48, location:'Pacific Grove, California', url:'/maize_meeting/2006', image:'/images/maize_meeting/asilomar.jpg'},
    {year:'2005', annual:47, location:'Lake Geneva, Wisconsin', url:'/maize_meeting/2005', image:'/images/maize_meeting/geneva.jpg'},
    {year:'2004', annual:46, location:'Mexico City, Mexico', url:'/maize_meeting/2004', image:'/images/maize_meeting/mexico.jpg'},
    {year:'2003', annual:45, location:'Lake Geneva, Wisconsin', url:'/maize_meeting/2003', image:'/images/maize_meeting/geneva.jpg'},
    {year:'2002', annual:44, location:'Kissimmee, Florida', url:'/maize_meeting/2002', image:'/images/maize_meeting/florida2.jpg'},
    {year:'2001', annual:43, location:'Lake Geneva, Wisconsin', url:'/maize_meeting/2001', image:'/images/maize_meeting/geneva.jpg'},
    {year:'2000', annual:42, location:"Coeur d'Alene, Idaho", url:'/maize_meeting/2000', image:'/images/maize_meeting/idaho.jpg'},
    {year:'1999', annual:41, location:'Lake Geneva, Wisconsin', url:'/maize_meeting/1999', image:'/images/maize_meeting/geneva.jpg'},
    {year:'1998', annual:40, location:'Lake Geneva, Wisconsin', url:'/maize_meeting/1998', image:'/images/maize_meeting/geneva.jpg'},
    {year:'1997', annual:39, location:'Clearwater Beach, Florida', url:'/maize_meeting/1997', image:'/images/maize_meeting/florida1.jpg'},
    {year:'1959', annual:1, location:'Allerton Park, Illinois', url:'/maize_meeting/1959', note:'original-meeting mock-up', image:'/maize_meeting/1959/images/allerton.jpg'}
  ];

  var activePeriod = 'all';

  function byId(id) { return document.getElementById(id); }
  function normalize(value) { return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim(); }
  function ordinal(number) {
    var mod100 = number % 100;
    if (mod100 >= 11 && mod100 <= 13) return number + 'th';
    return number + ({1:'st', 2:'nd', 3:'rd'}[number % 10] || 'th');
  }
  function periodFor(row) {
    var year = row.year === 'v2020' ? 2020 : Number(row.year);
    if (year < 1990) return 'historic';
    return Math.floor(year / 10) * 10 + 's';
  }

  function renderArchive() {
    var grid = byId('meeting-archive-grid');
    if (!grid) return;
    var query = normalize(byId('meeting-archive-query').value);
    var visible = archives.filter(function (row) {
      var periodMatch = activePeriod === 'all' || periodFor(row) === activePeriod;
      var searchText = normalize([row.year, row.annual, row.location, row.note].join(' '));
      var queryMatch = !query || query.split(' ').every(function (term) { return searchText.indexOf(term) !== -1; });
      return periodMatch && queryMatch;
    });

    grid.innerHTML = visible.map(function (row) {
      var note = row.note || ordinal(row.annual) + ' annual meeting';
      var media = row.image
        ? '<span class="meeting-archive-thumb"><img src="' + row.image + '" alt="" loading="lazy" /></span>'
        : '<span class="meeting-archive-thumb meeting-archive-placeholder" aria-hidden="true">Canceled</span>';
      return '<a href="' + row.url + '"' + (row.canceled ? ' class="is-canceled"' : '') + '>' + media + '<span class="meeting-archive-copy"><span class="meeting-archive-year">' + row.year + '</span><strong>' + row.location + '</strong><small>' + note + '</small></span></a>';
    }).join('');
    byId('meeting-archive-count').textContent = visible.length + (visible.length === 1 ? ' site shown' : ' sites shown');
    byId('meeting-archive-clear').hidden = !query;
    byId('meeting-archive-empty').hidden = visible.length !== 0;
  }

  function setPeriod(period) {
    activePeriod = period;
    document.querySelectorAll('[data-meeting-period]').forEach(function (button) {
      var active = button.getAttribute('data-meeting-period') === period;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    renderArchive();
  }

  function resetArchive() {
    byId('meeting-archive-query').value = '';
    setPeriod('all');
    byId('meeting-archive-query').focus();
  }

  function baseLayout() {
    return {
      paper_bgcolor: 'rgba(0,0,0,0)',
      plot_bgcolor: 'rgba(0,0,0,0)',
      font: {family: 'Arial, sans-serif', size: 9, color: '#68716b'},
      hoverlabel: {bgcolor: '#ffffff', bordercolor: '#e2ddd3'},
      showlegend: true,
      legend: {orientation: 'h', x: 0, y: 1.11, font: {size: 8}}
    };
  }

  function renderCharts() {
    // MGDB.chart handles the Plotly guard, lazy loading, resize, reduced motion,
    // and leaves the .mgdb-chart-fallback text in place if rendering ever fails.
    // The original removed the fallback up front, so a failure left a blank box.
    if (!window.MGDB) { return; }

    var attendanceLayout = baseLayout();
    attendanceLayout.margin = {l: 44, r: 12, t: 42, b: 54};
    attendanceLayout.xaxis = {type:'category', fixedrange: true, tickangle: -45, tickfont: {size: 8}, showgrid: false};
    attendanceLayout.yaxis = {fixedrange: true, rangemode: 'tozero', gridcolor: '#eee8df', title: 'Attendees'};
    attendanceLayout.hovermode = 'closest';
    window.MGDB.chart({ target: 'meeting-attendance-chart', layout: attendanceLayout, traces: [
      {type:'scatter', mode:'lines+markers', name:'Reported attendance', x:attendance.labels, y:attendance.values,
        text:attendance.locations, line:{color:'#9a6411', width:2}, marker:{color:'#f2a515', size:6, line:{color:'#fff', width:1}},
        hovertemplate:'<b>%{x}</b><br>%{text}<br>%{y:,} attendees<extra></extra>'},
      {type:'scatter', mode:'markers', name:'Virtual', x:['2020','2021'], y:[670,721], text:['Virtual replacement (v2020)','Virtual'],
        marker:{color:'#c82c22', size:9, symbol:'diamond', line:{color:'#fff', width:1}},
        hovertemplate:'<b>%{x}</b><br>%{text}<br>%{y:,} attendees<extra></extra>'},
      {type:'scatter', mode:'markers+text', name:'International host',
        x:['2004','2010','2014','2018','2026'], y:[432,438,584,456,412],
        text:['Mexico','Italy','China','France','Germany'],
        customdata:['Mexico City, Mexico','Riva del Garda, Italy','Beijing, China','Saint-Malo, France','Cologne, Germany'],
        textposition:['top left','bottom center','top center','bottom center','top center'],
        textfont:{color:'#501719', size:9}, cliponaxis:false,
        marker:{color:'#501719', size:9, symbol:'star', line:{color:'#f8c65d', width:1}},
        hovertemplate:'<b>%{x}</b><br>%{customdata}<br>%{y:,} attendees<extra></extra>'}
    ]});

    var programLayout = baseLayout();
    programLayout.margin = {l: 44, r: 12, t: 30, b: 48};
    programLayout.barmode = 'stack';
    programLayout.xaxis = {type:'category', fixedrange: true, tickangle: -45, tickfont: {size: 8}, showgrid: false};
    programLayout.yaxis = {fixedrange: true, rangemode: 'tozero', gridcolor: '#eee8df', title: 'Presentations'};
    window.MGDB.chart({ target: 'meeting-program-chart', layout: programLayout, traces: [
      {type:'bar', name:'Posters', x:program.labels, y:program.posters, marker:{color:'#f2a515'}, hovertemplate:'%{x}<br>%{y:,} posters<extra></extra>'},
      {type:'bar', name:'Talks', x:program.labels, y:program.talks, marker:{color:'#501719'}, hovertemplate:'%{x}<br>%{y:,} talks<extra></extra>'},
      {type:'bar', name:'Lightning talks', x:program.labels, y:program.lightning, marker:{color:'#e95e22'}, hovertemplate:'%{x}<br>%{y:,} lightning talks<extra></extra>'}
    ]});
  }

  function initialize() {
    if (!byId('meeting-title')) return;
    if (byId('meeting-archive-query')) {
      byId('meeting-archive-query').addEventListener('input', renderArchive);
      byId('meeting-archive-clear').addEventListener('click', function () {
        byId('meeting-archive-query').value = '';
        renderArchive();
        byId('meeting-archive-query').focus();
      });
      byId('meeting-archive-reset').addEventListener('click', resetArchive);
      document.querySelectorAll('[data-meeting-period]').forEach(function (button) {
        button.addEventListener('click', function () { setPeriod(button.getAttribute('data-meeting-period')); });
      });
      setPeriod('all');
    }
    renderCharts();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
}());
