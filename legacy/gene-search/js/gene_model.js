// file: gene_model.js
//
// purpose: javascript functions for the gene model data center
//

/*
// Attach event handler to download-all form
$(document).ready(function() {
  const da_form = document.querySelector('downloadall_form');
  if (da_form) {
    da_form.addEventListener('submit', e => {
      debugger;
      e.preventDefault();
      doDownloadAll();
    });//event listener
  }//form exists
});//document ready
*/

function gm_populate_bulk_positions(box) {
  document.getElementById(box).value = 
    "Chr3:1349161..1545106\nChr3:1851542..1980827\nChr3:7136721..7414401\nChr3:124200581..124666812";
}//gm_populate_bulk_positions


function gm_populate_gm_example(box) {
  document.getElementById(box).value = "Zm00001eb014280\n" +"Zm00001eb067740\n" + "Zm00001eb165610\n";
}//populateExample


function gm_populate_mixed_example(box) {
  document.getElementById(box).value = 
  "Zm00001eb014280\nZm00001eb165610_T002\nZm00001eb220660\nZm00001eb284010_T003\nZm00001eb411380_P001";
}//populate_gm_pos_example


/*maybe later
function gm_populate_bulk_markers(box) {
  document.getElementById(box).value = 
    "rs129364650\nrs129516059\nrs725203401\nrs129340526\nrs129381534\nrs129535361";
}//gm_populate_bulk_markers
*/

function gm_populate_score_example(box) {
  document.getElementById(box).value = 
    "Zm00001eb068070\nZm00001eb267570\nZm00001eb018590\nZm00001eb286860\nZm00001eb002510\nZm00001eb003760\n";
}//gm_populate_score_example


function showDetail() {
  var name1 = "id1";
  ele1 = document.getElementById(name1);
  var name2 = "id0";
  ele0 = document.getElementById(name2);
  
  if (ele1.style.display == "none") {
    ele1.style.display = "inline";
    ele0.style.display = "none";
  }
  else 
    ele1.style.display = "none";  
}//showDetail


function showDetail2() {
  var name1 = "id11";
  ele1 = document.getElementById(name1);
  var name2 = "id01";
  ele0 = document.getElementById(name2);
  
  if (ele1.style.display == "none") {
    ele1.style.display = "inline";
    ele0.style.display = "none";
  } 
  else 
    ele1.style.display = "none";
}//showDetail2


function showDetail3() {
  var name1 = "id12";
  ele1 = document.getElementById(name1);
  var name2 = "id02";
  ele0 = document.getElementById(name2);
  
  if (ele1.style.display == "none") {
    ele1.style.display = "inline";
    ele0.style.display = "none";
  } 
  else 
    ele1.style.display = "none";
}//showDetail3


function toggle_genome_tabs(tab, div, sec) {
  $('.'+sec+'_div').each(function(k, el) {
     $('#'+el.id).hide();
  });
    
  $('.'+sec+'_tab').each(function(k, el) {     
    if (el.id == tab) {
      $('#'+div).show();
      $('#'+div).css("display:block");
      $('#'+tab).removeClass('genome_tab_unfocus');
      $('#'+tab).addClass('genome_tab_focus');
    }
    else {
      $('#'+el.id).removeClass('genome_tab_focus');
      $('#'+el.id).addClass('genome_tab_unfocus');
    }
  });
}//toggle_genome_tabs


// Toggles tabs in cases where the tabs are included with the div being toggled.
//  (That is, each div contains its own copy of the tabs)
function toggle_tabs(val) {
  $('.gbdiv').each(function(k, el) {
    if (el.id == val) {
      $('#'+el.id).show();
    }
    else {
      $('#'+el.id).hide();
    }
  });
}//toggle_tabs - val


// Toggles tabs in cases where the tabs are included with the div being toggled.
//  (That is, each div contains its own copy of the tabs)
function toggle_tabs(val, cssClass) {
  $('.'+cssClass).each(function(k, el) {
    if (el.id == val) {
      $('#'+el.id).show();
    }
    else {
      $('#'+el.id).hide();
    }
  });
}//toggle_tabs - val+class

// Toggles tabs in cases where the tabs are included with the div being toggled.
//  (That is, each div contains its own copy of the tabs)
function toggle_tabs_efp(val, cssClass) {
  $('.'+cssClass).each(function(k, el) {
    if (el.id == val) {
      $('#'+el.id).css("display", "inline");
      let img_el = $('#'+el.id).find('img').first();
      let img_url = img_el.attr('url'); //test url
      let img_src = img_el.attr('src');
      if (img_src == "") {
        img_el.attr('src', img_url);
      }
      
    }
    else {
      $('#'+el.id).css("display", "none");
    }
  });
}//toggle_tab


function toggle_transcript(tab_set, div_set, val) {
  div_set = div_set.replace('.', '\\.');  // escape any .'s
  $('#'+div_set).children('.trans_div').each(function(k, el) {
     e = el.id.replace('.', '\\.');
     var s = $('#'+e);
     $('#'+e).hide();
  });

  tab_set = tab_set.replace('.', '\\.');  // escape any .'s
  $('#'+tab_set).children('.trans_tab').each(function(k, tab) {
    if (tab.id == val) {
      var v = val.replace('.', '\\.');
      $('#r'+v).show();
      $('#'+v).removeClass('gene_model_tab_unfocus');
      $('#'+v).addClass('gene_model_tab_focus');
      $('#'+v+'_div').show();
    }
    else {
      var t = tab.id.replace('.', '\\.');
      $('#r'+t).hide();
      $('#'+t).removeClass('gene_model_tab_focus');
      $('#'+t).addClass('gene_model_tab_unfocus');
    }
  });
}//toggle_transcript


function updateOptions() {
  var val = list_form.elements["in_type"].value;
  if (val == "gene") {
    document.list_form.out_type.options.length=0;
    document.list_form.out_type.options[0]=new Option("Sequence: genomic", "genomic", true, false);
    document.list_form.out_type.options[1]=new Option("Sequence: cDNA", "cdna", false, false);
    document.list_form.out_type.options[2]=new Option("Sequence: CDS", "cds", false, false);
    document.list_form.out_type.options[3]=new Option("Sequence: mRNA", "mrna", false, false);
    document.list_form.out_type.options[4]=new Option("Sequence: protein", "protein", false, false);
    document.list_form.out_type.options[5]=new Option("Raw data", "raw", false, false);
    document.list_form.out_type.options[6]=new Option("Associated genes", "list", false, false);    
    document.getElementById("model_type_html").style.display = "inline";
  } 
  else if (val == "transcript") {
    document.list_form.out_type.options.length=0;
    document.list_form.out_type.options[0]=new Option("Sequence: cDNA", "cdna", true, false);
    document.list_form.out_type.options[1]=new Option("Sequence: CDS", "cds", false, false);
    document.list_form.out_type.options[2]=new Option("Sequence: mRNA", "mrna", false, false);
    document.list_form.out_type.options[3]=new Option("Raw data", "raw", false, false);
    document.getElementById("model_type_html").style.display = "none";
  } 
  else if (val == "translation") {
    document.list_form.out_type.options.length=0;
    document.list_form.out_type.options[0]=new Option("Sequence: protein", "protein", true, false);
    document.list_form.out_type.options[1]=new Option("Raw data", "raw", false, false);    
    document.getElementById("model_type_html").style.display = "none";
  } 
}//updateOptions


