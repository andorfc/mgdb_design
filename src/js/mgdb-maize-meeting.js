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
    /* Cologne has been held; it belongs in the archive rather than only in the
       attendance chart. The photograph is cologne_cathedral.jpg, not the legacy
       cologne.png -- that one is 101x101 with no credit entry and no known
       licence. This is the Rhine view from the Deutz bridge, 10000x5390 at
       source, so it needs almost no cropping to fill the 122px band. */
    {year:'2026', annual:68, location:'Cologne, Germany', url:'/mgc/maizemeeting/2026/', image:'/images/maize_meeting/cologne_cathedral.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2026Program.pdf'},
    {year:'2025', annual:67, location:'St. Louis, Missouri', url:'/mgc/maizemeeting/2025', image:'/images/maize_meeting/stlouis.png', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2025Program.pdf'},
    {year:'2024', annual:66, location:'Raleigh, North Carolina', url:'/mgc/maizemeeting/2024', image:'/images/maize_meeting/raleigh.png', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2024Program.pdf'},
    {year:'2023', annual:65, location:'St. Louis, Missouri', url:'/mgc/maizemeeting/2023', image:'/images/maize_meeting/stlouis.png', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2023Program.pdf'},
    {year:'2022', annual:64, location:'St. Louis, Missouri', url:'/mgc/maizemeeting/2022', image:'/images/maize_meeting/stlouis.png', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2022Program.pdf'},
    {year:'2021', annual:63, location:'Virtual', url:'/mgc/maizemeeting/2021', image:'/images/maize_meeting/virtual.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2021Program.pdf'},
    {year:'v2020', annual:62, location:'Virtual', url:'/maize_meeting/v2020', note:'virtual meeting', image:'/images/maize_meeting/virtual.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2020Program.pdf'},
    {year:'2020', annual:62, location:'Keauhou Bay, Hawaii', url:'/maize_meeting/2020', note:'planned in-person meeting · canceled', canceled:true},
    {year:'2019', annual:61, location:'St. Louis, Missouri', url:'/maize_meeting/2019', image:'/images/maize_meeting/stlouis.png', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2019Program.pdf'},
    {year:'2018', annual:60, location:'Saint-Malo, France', url:'/maize_meeting/2018', image:'/images/maize_meeting/stmalo.png', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2018Program.pdf'},
    {year:'2017', annual:59, location:'St. Louis, Missouri', url:'/maize_meeting/2017', image:'/images/maize_meeting/stlouis.png', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2017Program.pdf'},
    {year:'2016', annual:58, location:'Jacksonville, Florida', url:'/maize_meeting/2016', image:'/images/maize_meeting/jacksonville.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2016Program.pdf'},
    {year:'2015', annual:57, location:'St. Charles, Illinois', url:'/maize_meeting/2015', image:'/images/maize_meeting/chicago.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2015Program.pdf'},
    {year:'2014', annual:56, location:'Beijing, China', url:'/maize_meeting/2014', image:'/images/maize_meeting/beijing.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2014Program.pdf'},
    {year:'2013', annual:55, location:'St. Charles, Illinois', url:'/maize_meeting/2013', image:'/images/maize_meeting/chicago.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2013Program.pdf'},
    {year:'2012', annual:54, location:'Portland, Oregon', url:'/maize_meeting/2012', image:'/images/maize_meeting/portland.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2012Program.pdf'},
    {year:'2011', annual:53, location:'St. Charles, Illinois', url:'/maize_meeting/2011', image:'/images/maize_meeting/chicago.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2011Program.pdf'},
    {year:'2010', annual:52, location:'Riva del Garda, Italy', url:'/maize_meeting/2010', image:'/images/maize_meeting/italy.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2010Program.pdf'},
    {year:'2009', annual:51, location:'St. Charles, Illinois', url:'/maize_meeting/2009', image:'/images/maize_meeting/chicago.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2009Program.pdf'},
    {year:'2008', annual:50, location:'Washington, DC', url:'/maize_meeting/2008', image:'/images/maize_meeting/dc.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2008Program.pdf'},
    {year:'2007', annual:49, location:'St. Charles, Illinois', url:'/maize_meeting/2007', image:'/images/maize_meeting/chicago.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2007Program.pdf'},
    {year:'2006', annual:48, location:'Pacific Grove, California', url:'/maize_meeting/2006', image:'/images/maize_meeting/asilomar.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2006Program.pdf'},
    {year:'2005', annual:47, location:'Lake Geneva, Wisconsin', url:'/maize_meeting/2005', image:'/images/maize_meeting/geneva.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2005Program.pdf'},
    {year:'2004', annual:46, location:'Mexico City, Mexico', url:'/maize_meeting/2004', image:'/images/maize_meeting/mexico.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2004Program.pdf'},
    {year:'2003', annual:45, location:'Lake Geneva, Wisconsin', url:'/maize_meeting/2003', image:'/images/maize_meeting/geneva.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2003Program.pdf'},
    {year:'2002', annual:44, location:'Kissimmee, Florida', url:'/maize_meeting/2002', image:'/images/maize_meeting/florida2.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2002Program.pdf'},
    {year:'2001', annual:43, location:'Lake Geneva, Wisconsin', url:'/maize_meeting/2001', image:'/images/maize_meeting/geneva.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2001Program.pdf'},
    {year:'2000', annual:42, location:"Coeur d'Alene, Idaho", url:'/maize_meeting/2000', image:'/images/maize_meeting/idaho.jpg', abstracts:'https://documents.maizegdb.org/maizemeeting/abstracts/2000Program.pdf'},
    {year:'1999', annual:41, location:'Lake Geneva, Wisconsin', url:'/maize_meeting/1999', image:'/images/maize_meeting/geneva.jpg'},
    {year:'1998', annual:40, location:'Lake Geneva, Wisconsin', url:'/maize_meeting/1998', image:'/images/maize_meeting/geneva.jpg'},
    {year:'1997', annual:39, location:'Clearwater Beach, Florida', url:'/maize_meeting/1997', image:'/images/maize_meeting/florida1.jpg'},
    {year:'1959', annual:1, location:'Allerton Park, Illinois', url:'/maize_meeting/1959', note:'original-meeting mock-up', image:'/images/maize_meeting/allerton.jpg'}
  ];


  /* Steering committee, local host and ex-officio members, 2018-2026.

     Restored 2026-09-09 from https://www.maizegdb.org/maize_meeting/, which is
     still the only place this is recorded -- the first redesign dropped it, and
     it is not in `legacy/`. Roles are the ones the source annotates in
     parentheses; everyone else is a member with no role shown.

     2027 is not here: its committee sits in the upcoming card's own expander in
     templates/static/mgdb_maize_meeting.bau.

     Person ids are kept so each name can link to its /person record. Two of the
     source's ids are wrong and are corrected here rather than copied:
     Frank Hochholdinger is 114023, not 1232861 (which is Jeffrey Ross-Ibarra's
     record, and the source uses it for Frank in every year from 2022 on), and
     Madelaine Bartlett is 974454, not 4974494 (which resolves to nothing --
     it reads like 974454 with a digit in front). Both reported for the source
     page; the names themselves were right. */
  var leadership = {
    '2026': {
      committee: [{id:114023,name:"Frank Hochholdinger",role:"Chair"}, {id:1079330,name:"Anthony Studer",role:"Co-Chair"}, {id:134307,name:"Sherry Flint-Garcia",role:"Previous Chair"}, {id:2773322,name:"Oyenike Adeyemo"}, {id:15943,name:"Hank Bass"}, {id:1187651,name:"Sara Larsson"}, {id:9034294,name:"Penelope Lindsay"}, {id:9034269,name:"Katie Murphy"}, {id:1280767,name:"Cinta Romay"}, {id:40350,name:"Graziana Taramino"}, {id:1187774,name:"Feng Tian"}],
      host: [{id:114023,name:"Frank Hochholdinger"}],
      ex: [{id:1204262,name:"Carson Andorf"}, {id:882336,name:"Darwin Campbell"}, {id:3094837,name:"Sara Miller"}, {id:2772801,name:"John Portwood"}, {id:13016,name:"Marty Sachs"}, {id:3171877,name:"Erin Sparks"}]
    },
    '2025': {
      committee: [{id:134307,name:"Sherry Flint-Garcia",role:"Chair"}, {id:114023,name:"Frank Hochholdinger",role:"Co-Chair"}, {id:3173153,name:"Rubén Rellán Álvarez",role:"Previous Chair"}, {id:2773322,name:"Oyenike Adeyemo"}, {id:15943,name:"Hank Bass"}, {id:10000404,name:"Melissa Draves"}, {id:3171591,name:"Keting Chen"}, {id:9034269,name:"Katie Murphy"}, {id:1280767,name:"Cinta Romay"}, {id:40350,name:"Graziana Taramino"}, {id:1187774,name:"Feng Tian"}, {id:953774,name:"Petra Wolters"}],
      host: [{id:13016,name:"Marty Sachs"}],
      ex: [{id:1204262,name:"Carson Andorf"}, {id:882336,name:"Darwin Campbell"}, {id:3094837,name:"Sara Miller"}, {id:2772801,name:"John Portwood"}, {id:13016,name:"Marty Sachs"}, {id:3171877,name:"Erin Sparks"}]
    },
    '2024': {
      committee: [{id:3173153,name:"Rubén Rellán Álvarez",role:"Chair"}, {id:134307,name:"Sherry Flint-Garcia",role:"Co-Chair"}, {id:1280599,name:"Matthew Hufford",role:"Previous Chair"}, {id:2773322,name:"Oyenike Adeyemo"}, {id:974454,name:"Madelaine Bartlett"}, {id:15943,name:"Hank Bass"}, {id:3530965,name:"Lander Geadelmann"}, {id:114023,name:"Frank Hochholdinger"}, {id:3171657,name:"Stephanie Klein"}, {id:40350,name:"Graziana Taramino"}, {id:1187774,name:"Feng Tian"}, {id:953774,name:"Petra Wolters"}],
      host: [{id:13016,name:"Marty Sachs"}],
      ex: [{id:1204262,name:"Carson Andorf"}, {id:882336,name:"Darwin Campbell"}, {id:2772801,name:"John Portwood"}, {id:13016,name:"Marty Sachs"}, {id:3171877,name:"Erin Sparks"}]
    },
    '2023': {
      committee: [{id:1280599,name:"Matthew Hufford",role:"Chair"}, {id:3173153,name:"Rubén Rellán Álvarez",role:"Co-Chair"}, {id:3171877,name:"Erin Sparks",role:"Previous Chair"}, {id:2773322,name:"Oyenike Adeyemo"}, {id:974454,name:"Madelaine Bartlett"}, {id:100066,name:"Mei Guo"}, {id:114023,name:"Frank Hochholdinger"}, {id:3171720,name:"Maria Angelica Sanclemente"}, {id:3218426,name:"Aimee Schulz"}, {id:953774,name:"Petra Wolters"}, {id:194162,name:"Marna Yandeau-Nelson"}],
      host: [{id:13016,name:"Marty Sachs"}],
      ex: [{id:1204262,name:"Carson Andorf"}, {id:99999,name:"David Braun"}, {id:882336,name:"Darwin Campbell"}, {id:2772801,name:"John Portwood"}, {id:13016,name:"Marty Sachs"}]
    },
    '2022': {
      committee: [{id:3171877,name:"Erin Sparks",role:"Chair"}, {id:1280599,name:"Matthew Hufford",role:"Co-Chair"}, {id:194162,name:"Marna Yandeau-Nelson",role:"Previous Chair"}, {id:974454,name:"Madelaine Bartlett"}, {id:3171617,name:"Joe Gage"}, {id:100066,name:"Mei Guo"}, {id:114023,name:"Frank Hochholdinger"}, {id:17002,name:"Todd Jones"}, {id:3173153,name:"Rubén Rellán Álvarez"}, {id:3218434,name:"Samantha Snodgrass"}, {id:415500,name:"Maud Tenaillon"}, {id:953774,name:"Petra Wolters"}],
      host: [{id:13016,name:"Marty Sachs"}],
      ex: [{id:1204262,name:"Carson Andorf"}, {id:99999,name:"David Braun"}, {id:882336,name:"Darwin Campbell"}, {id:2772801,name:"John Portwood"}, {id:13016,name:"Marty Sachs"}]
    },
    '2021': {
      committee: [{id:194162,name:"Marna Yandeau-Nelson",role:"Chair"}, {id:3171877,name:"Erin Sparks",role:"Co-Chair"}, {id:974454,name:"Madelaine Bartlett"}, {id:100066,name:"Mei Guo"}, {id:1280599,name:"Matthew Hufford"}, {id:17002,name:"Todd Jones"}, {id:952519,name:"Hilde Nelissen"}, {id:114023,name:"Jeff Ross-Ibarra"}, {id:415500,name:"Maud Tenaillon"}, {id:641776,name:"Clint Whipple"}, {id:1232807,name:"Yongrui Wu"}],
      host: [{id:13016,name:"Marty Sachs"}],
      ex: [{id:1204262,name:"Carson Andorf"}, {id:99999,name:"David Braun"}, {id:882336,name:"Darwin Campbell"}, {id:2772801,name:"John Portwood"}, {id:13016,name:"Marty Sachs"}]
    },
    '2020': {
      committee: [{id:641776,name:"Clint Whipple",role:"Chair"}, {id:194162,name:"Marna Yandeau-Nelson",role:"Co-Chair"}, {id:16566,name:"Mike Muszynski"}, {id:487234,name:"Andrea Gallavotti"}, {id:952519,name:"Hilde Nelissen"}, {id:114023,name:"Jeff Ross-Ibarra"}, {id:1232807,name:"Yongrui Wu"}, {id:17002,name:"Todd Jones"}, {id:100066,name:"Mei Guo"}, {id:3171877,name:"Erin Sparks"}, {id:415500,name:"Maud Tenaillon"}],
      host: [{id:16566,name:"Mike Muszynski"}],
      ex: [{id:1204262,name:"Carson Andorf"}, {id:99999,name:"David Braun"}, {id:2772801,name:"John Portwood"}, {id:13016,name:"Marty Sachs"}]
    },
    '2019': {
      committee: [{id:16566,name:"Mike Muszynski",role:"Chair"}, {id:641776,name:"Clint Whipple",role:"Co-Chair"}, {id:952519,name:"Hilde Nelissen"}, {id:487234,name:"Andrea Gallavotti"}, {id:917904,name:"Andrea Eveland"}, {id:144438,name:"Maike Stam"}, {id:1079323,name:"Thomas Slewinski"}, {id:1079127,name:"Sylvia Sousa"}, {id:172645,name:"Natalia de Leon"}, {id:114023,name:"Jeff Ross-Ibarra"}, {id:1232807,name:"Yongrui Wu"}, {id:17002,name:"Todd Jones"}],
      host: [{id:13016,name:"Marty Sachs"}],
      ex: [{id:1204262,name:"Carson Andorf"}, {id:99999,name:"David Braun"}, {id:17419,name:"Alain Charcosset"}, {id:2772801,name:"John Portwood"}, {id:13016,name:"Marty Sachs"}]
    },
    '2018': {
      committee: [{id:17419,name:"Alain Charcosset",role:"Chair"}, {id:16566,name:"Mike Muszynski",role:"Co-Chair"}, {id:16249,name:"Erich Grotewold"}, {id:884558,name:"Karen McGinnis"}, {id:2714384,name:"Stephen Novak"}, {id:487234,name:"Andrea Gallavotti"}, {id:981183,name:"Jianbing Yan"}, {id:917904,name:"Andrea Eveland"}, {id:144438,name:"Maike Stam"}, {id:1079323,name:"Thomas Slewinski"}, {id:1079127,name:"Sylvia Sousa"}, {id:172645,name:"Natalia de Leon"}],
      host: [{id:415500,name:"Maud Tenaillon"}],
      ex: [{id:1204262,name:"Carson Andorf"}, {id:99999,name:"David Braun"}, {id:13016,name:"Marty Sachs"}]
    }
  };

  /* Photograph attribution. Every venue image is CC BY or CC BY-SA, which
     require credit, so each card carries the photographer's name and the licence
     and the archive section renders a full credits list underneath. Keyed by
     image path so a row without a photograph simply gets no credit. */
  var photoCredits = {
    '/images/maize_meeting/allerton.jpg': {creator:"D Finnigan", license:"CC BY-SA 3.0", title:"Allerton House and Pond at Allerton Park.jpg", source:"https://commons.wikimedia.org/wiki/File:Allerton_House_and_Pond_at_Allerton_Park.jpg"},
    '/images/maize_meeting/asilomar.jpg': {creator:"UnifiedFunctionality", license:"CC BY-SA 4.0", title:"Asilomar State Beach at Sunset.jpg", source:"https://commons.wikimedia.org/wiki/File:Asilomar_State_Beach_at_Sunset.jpg"},
    '/images/maize_meeting/beijing.jpg': {creator:"Peter23", license:"CC BY-SA 3.0", title:"Beijing national stadium.jpg", source:"https://commons.wikimedia.org/wiki/File:Beijing_national_stadium.jpg"},
    '/images/maize_meeting/chicago.jpg': {creator:"Aneekr at English Wikipedia", license:"CC BY-SA 3.0", title:"St. Charles Municipal Building (St. Charles, IL) 09", source:"https://commons.wikimedia.org/w/index.php?curid=6636572"},
    '/images/maize_meeting/cologne_cathedral.jpg': {creator:"J\u00f6rg Braukmann", license:"CC BY-SA 4.0", title:"Dom (Deutzer Br\u00fccke).jpg", source:"https://commons.wikimedia.org/wiki/File:Dom_(Deutzer_Br%C3%BCcke).jpg"},
    '/images/maize_meeting/dc.jpg': {creator:"Sergiy Galyonkin from Raleigh, USA", license:"CC BY-SA 2.0", title:"Washington DC - United States Capitol at blue hour (51282289914)", source:"https://commons.wikimedia.org/w/index.php?curid=120353198"},
    '/images/maize_meeting/florida1.jpg': {creator:"TampaThings.com", license:"CC BY-SA 4.0", title:"Clearwater-beach-florida-pier-60", source:"https://commons.wikimedia.org/w/index.php?curid=107581285"},
    '/images/maize_meeting/florida2.jpg': {creator:"Visitor7", license:"CC BY-SA 3.0", title:"Kissimmee Lakefront Park-1.jpg", source:"https://commons.wikimedia.org/w/index.php?curid=32084736"},
    '/images/maize_meeting/geneva.jpg': {creator:"Yinan Chen", license:"Public domain", title:"Gfp-wisconsin-lake-geneva-at-dusk.jpg", source:"https://commons.wikimedia.org/wiki/File:Gfp-wisconsin-lake-geneva-at-dusk.jpg"},
    '/images/maize_meeting/idaho.jpg': {creator:"Ken Lund from Reno, Nevada, USA", license:"CC BY-SA 2.0", title:"Lake Coeur d'Alene, Coeur d'Alene, Idaho (50083363521).jpg", source:"https://commons.wikimedia.org/wiki/File:Lake_Coeur_d%27Alene,_Coeur_d%27Alene,_Idaho_(50083363521).jpg"},
    '/images/maize_meeting/italy.jpg': {creator:"High Contrast", license:"CC BY 3.0 DE", title:"Riva del Garda, Italy.jpg", source:"https://commons.wikimedia.org/wiki/File:Riva_del_Garda,_Italy.jpg"},
    '/images/maize_meeting/jacksonville.jpg': {creator:"Quintin Soloviev", license:"CC BY 4.0", title:"Jacksonville skyline", source:"https://commons.wikimedia.org/w/index.php?curid=182002489"},
    '/images/maize_meeting/mexico.jpg': {creator:"Carolina L\u00f3pez", license:"CC BY 2.0", title:"Palacio de Bellas Artes.jpg", source:"https://commons.wikimedia.org/w/index.php?curid=4269986"},
    '/images/maize_meeting/portland.jpg': {creator:"S.Stults", license:"CC BY-SA 4.0", title:"Mount Hood overlooks Portland,Oregon.png", source:"https://commons.wikimedia.org/wiki/File:Mount_Hood_overlooks_Portland,Oregon.png"},
    '/images/maize_meeting/raleigh.png': {creator:"Mark Turner", license:"Public domain", title:"Downtown-Raleigh-from-Western-Boulevard-Overpass-20081012.jpeg", source:"https://commons.wikimedia.org/wiki/File:Downtown-Raleigh-from-Western-Boulevard-Overpass-20081012.jpeg"},
    '/images/maize_meeting/stlouis.png': {creator:"Daniel Schwen", license:"CC BY-SA 4.0", title:"St Louis night expblend.jpg", source:"https://commons.wikimedia.org/wiki/File:St_Louis_night_expblend.jpg"},
    '/images/maize_meeting/stmalo.png': {creator:"Gzen92", license:"CC BY-SA 4.0", title:"Remparts (Saint-Malo) (2).jpg", source:"https://commons.wikimedia.org/wiki/File:Remparts_(Saint-Malo)_(2).jpg"},
  };

  function creditFor(image) { return image ? photoCredits[image] : null; }

  /* Archive photographs are referenced from this script, not from a resource
     tag, so Bauplan's automatic ?v= never reaches them and the CDN went on
     serving the pre-replacement images. Bump this when a photograph changes. */
  /* Bumped when a venue photograph is replaced: the paths do not change, so
     without this a returning reader keeps the cached old frame. */
  var PHOTO_VERSION = '4';

  function photoUrl(path) {
    return path ? path + (path.indexOf('?') === -1 ? '?v=' + PHOTO_VERSION : '') : path;
  }

  var activePeriod = 'all';

  function byId(id) { return document.getElementById(id); }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
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

  /* The steering committee, local host and ex-officio members for one meeting.

     Closed by default and inside the card: 11-13 names on every card from 2018
     on would push the rest of the archive off the screen, and the reason to
     open one is to look up a particular year. <details> rather than scripted
     state -- it is open/closed and nothing else, it works before the script
     runs, and the browser's own find-in-page can reach inside it.

     Names link to their /person record, which is what the source page did. */
  function personList(people) {
    return '<ul>' + people.map(function (p) {
      return '<li><a href="/person?id=' + p.id + '">' + escapeHtml(p.name) + '</a>'
           + (p.role ? ' <span>' + escapeHtml(p.role) + '</span>' : '') + '</li>';
    }).join('') + '</ul>';
  }

  function leadershipFor(row) {
    /* Both 2020 rows -- the canceled in-person meeting and the virtual one that
       replaced it -- are the same meeting year and the same committee, and the
       source records one "2020 Steering Committee". They share it, the way they
       already share the abstract book. */
    var l = leadership[row.year === 'v2020' ? '2020' : row.year];
    if (!l) { return ''; }
    var right = '';
    if (l.host && l.host.length) {
      right += '<h4>Local host</h4>' + personList(l.host);
    }
    if (l.ex && l.ex.length) {
      right += '<h4>Ex-officio members</h4>' + personList(l.ex);
    }
    return '<details class="meeting-archive-more"><summary>More details</summary>'
      + '<div class="meeting-event-people">'
      + '<div><h4>Steering committee</h4>' + personList(l.committee) + '</div>'
      + (right ? '<div>' + right + '</div>' : '')
      + '</div></details>';
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
        ? '<span class="meeting-archive-thumb"><img src="' + photoUrl(row.image) + '" alt="" loading="lazy" /></span>'
        : '<span class="meeting-archive-thumb meeting-archive-placeholder" aria-hidden="true">'
          + (row.canceled ? 'Canceled' : row.year) + '</span>';
      /* The card is no longer one big link: it carries two of its own -- the
         archived meeting website and the abstract book -- and a link inside a
         link is not markup a browser will honour. The photographer credit that
         used to sit here is gone; it is repeated in full under Photograph
         credits below, and this is space the links needed. */
      var links = '<a class="meeting-archive-link" href="' + row.url + '">Website</a>';
      if (row.abstracts) {
        links += '<a class="meeting-archive-link" href="' + row.abstracts + '" target="_blank" rel="noopener">Abstract book <span aria-hidden="true">&nearr;</span></a>';
      }
      return '<article class="meeting-archive-card' + (row.canceled ? ' is-canceled' : '') + '">' + media
        + '<span class="meeting-archive-copy"><span class="meeting-archive-year">' + row.year
        + '</span><strong>' + row.location + '</strong><small>' + note + '</small>'
        + '<span class="meeting-archive-links">' + links + '</span>'
        + leadershipFor(row) + '</span></article>';
    }).join('');
    byId('meeting-archive-count').textContent = visible.length + (visible.length === 1 ? ' site shown' : ' sites shown');
    byId('meeting-archive-clear').hidden = !query;
    byId('meeting-archive-empty').hidden = visible.length !== 0;
    renderCredits();
  }

  /* The complete attribution list. Rendered once; CC BY and CC BY-SA are
     satisfied by naming the photographer, the work, and the licence, and by
     linking back to the source page. */
  function renderCredits() {
    var host = byId('meeting-photo-credits');
    if (!host || host.getAttribute('data-rendered') === 'true') { return; }
    var seen = {};
    var items = [];
    archives.forEach(function (row) {
      var c = creditFor(row.image);
      if (!c || seen[row.image]) { return; }
      seen[row.image] = true;
      items.push('<li><strong>' + escapeHtml(row.location) + '</strong> &mdash; '
        + '<a href="' + escapeHtml(c.source) + '">' + escapeHtml(c.title || 'photograph') + '</a>'
        + ' by ' + escapeHtml(c.creator) + ', ' + escapeHtml(c.license) + '</li>');
    });
    if (!items.length) { return; }
    host.innerHTML = '<ul>' + items.join('') + '</ul>';
    host.setAttribute('data-rendered', 'true');
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
      hoverlabel: {bgcolor: '#ffffff', bordercolor: '#c9cfc6', font: {color: '#1b2a20', size: 12}},
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

  /* The tab bar is new on this page. Shared behaviour rather than another
     hand-rolled spy; `watch` is the archive grid, which changes height as the
     period filter narrows it. */
  function initTabs() {
    if (window.MGDB && window.MGDB.sectionTabs) {
      window.MGDB.sectionTabs({ watch: '#meeting-archive-grid' });
    }
  }

  function initialize() {
    initTabs();
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
