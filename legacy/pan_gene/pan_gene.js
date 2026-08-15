// file: pan_gene.js
//
// purpose: javascript support for pan-gene pages.

var busy = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
var data_url = '/record_data/pan_gene_data.php';
var locus_data_url = '/record_data/pan_gene_locus_data.php';
var base_align_url = "https://ftpprivate.maizegdb.org/pangene/pan-zea/";
var active_ajax = {};

function clearAdvSearchForm() {
  document.getElementById('pan_gene-adv_search_form').reset();
  $('#adv_results').html('');
}//clearAdvSearchForm


function doAdvPanGeneDownload(url) {
  let filename = 'pan_gene.txt';
  $('#download_status_div').text('preparing download file ' + filename);
  
  // Create a random ID
  let job_id = getJobID();
  
  let filters = getAdvFilters();
  let data = Object.assign({job_id: job_id, download: 'yes', filename: filename}, filters);

  $.post(url, data)
    .fail(function(data) {
      let msg = 'download failed. ';
      msg += 'You may have to download the full set of pan-genes from the ';
      msg += '<a href="downloads.maizegdb.org/Pan-genes">download directory</a>';
      $('#download_status_div').html(msg);
    })
    .done(function(data) {
      $('#download_status_div').append('.');
      checkDownload(job_id, filename, 1);
    });
}//doAdvPanGeneDownload


function downloadPanGeneExemplars(url) {
  // Download exemplar sequence for a set of pan-genes
  let filename = 'pan_gene_exemplars.txt';
  $('#download_status_div').text('preparing download file ' + filename);
  
  // Create a random ID
  let job_id = getJobID();
  
  let filters = getAdvFilters();
  let data = Object.assign(
    {job_id: job_id, download: 'yes', filename: filename, exemplars: 'yes'}, 
    filters
  );

  $.post(url, data)
    .fail(function(data) {
      let msg = 'download failed. ';
      msg += 'You may have to download the full set of pan-genes from the ';
      msg += '<a href="downloads.maizegdb.org/Pan-genes">download directory</a>';
      $('#download_status_div').html(msg);
    })
    .done(function(data) {
      $('#download_status_div').append('.');
      checkDownload(job_id, filename, 1);
    });
}//downloadPanGeneExemplars


function downloadPanGeneSequence(type, pan_gene_name, download_url) {
  // Download sequence for a single pan-gene
  var fail_msg = 'download failed. ';
  fail_msg += 'You may have to download the full set of pan-genes from the ';
  fail_msg += 'Pan-gene/Pan-Zea download directory.';
  
  var url = '/record_data/pan_gene_seq.php';
  var data = {type: type, pan_gene_name: pan_gene_name, download_url: download_url};
  $('#pan_gene_downloading').show();
  $.post(url, data)
    .fail(function(content) {
      // handle fail
      alert(fail_msg);
    })
    .done(function(content) {
      if (content == '') {
        // handle fail
        $('#pan_gene_downloading').hide();
        alert(fail_msg);
      }
      else {
        // Create file object to download
        const file = new File([content], 'pan_gene_'+type+'.fa', 
              {type: 'text/plain'});
        
        // Force download by creating, triggering, then removing an anchor element
        const link = document.createElement('a')
        const url = URL.createObjectURL(file)

        link.href = url
        link.download = file.name
        document.body.appendChild(link)
        link.click()

        document.body.removeChild(link)
        window.URL.revokeObjectURL(url)
        
        $('#pan_gene_downloading').hide();
      }//else
    });//post
  
}//DownloadPanGeneSequence


function filterBySet() {
  let filter = document.getElementById('pan_gene-assembly_set').value;
  let id = document.getElementById('page-identifier').value;
  let url= '/pan_gene_center/pan_gene/' + id + '?filter=' + filter;
  window.location.href = url; 
}//filterBySet


function getAdvFilters() {
  let filters = {};
  
  // Add all checkbox params to array
  filters['box_appear'] = document.getElementById('box_appear').checked;
  filters['box_gene_models'] = document.getElementById('box_gene_models').checked;
  filters['box_locus'] = document.getElementById('box_locus').checked;
  filters['box_min'] = document.getElementById('box_min').checked;
  filters['box_max'] = document.getElementById('box_max').checked;
  filters['box_min_annots'] = document.getElementById('box_min_annots').checked;
  filters['box_max_annots'] = document.getElementById('box_max_annots').checked;
  filters['box_not_appear'] = document.getElementById('box_not_appear').checked;
  filters['box_protein'] = document.getElementById('box_protein').checked;
  filters['box_trait'] = document.getElementById('box_trait').checked;

  // Add all values to array
  filters['appear'] = $('#appear').val();  // This works for a multi-select
  filters['gene_models'] = document.getElementById('gene_models').value; 
  filters['min'] = document.getElementById('min').value; 
  filters['max'] = document.getElementById('max').value;
  filters['min_annots'] = document.getElementById('min_annots').value;
  filters['max_annots'] = document.getElementById('max_annots').value;
  filters['not_appear'] = $('#not_appear').val();  // This works for a multi-select
  filters['proteins'] = document.getElementById('proteins').value;

  return filters;
}//getAdvFilters


function goToGCV(ref_accession, chr_accession, chr_num, gm_start, gm_end) {
  let cmp_idstr = $('#compare-genome').find(':selected').val();
  if (cmp_idstr == '') {
    return;
  }
  let cmp_ids = cmp_idstr.split('|');  // GB|internal
  let chr_ids = cmp_ids[2].split(':'); // GB chr accessions
  // example: https://www.ncbi.nlm.nih.gov/genome/cgv/browse/
  let cgv_url = "https://www.ncbi.nlm.nih.gov/genome/cgv/browse/";
  // example: GCA_902167135.1/GCF_902167145.1/50605/
  cgv_url += cmp_ids[0] + '.1/' + ref_accession + '/' + cmp_ids[1] +'/';
  // example 4577#LR622001.1/NC_05099.1:104208608-104381477/size=10000
  // 4577 = NCBI taxonomy accession for maize
  cgv_url += '4577#' + chr_ids[chr_num] + '/' + chr_accession + ':' + gm_start + '-' + gm_end + '/';
  // Add some padding(?)
  cgv_url += 'size=10000';

  window.open(cgv_url, '_blank');
}//goToGCV


function MSAviewerChangeAlignment(msa_view, pan_gene_name) {
  let which = $('input[name="pan_gene-alignment"]:checked').val();
  let dir = (which == 'cds') ? 'cds-alignments/' : 'protein-alignments/';
  $('#msa_view-download').attr("href", base_align_url+dir+pan_gene_name);
  msa_view.u.file.importURL(base_align_url + dir + pan_gene_name, function() {
    msa_view.render();
    //document.getElementsByClassName("smenubar")[0].style.visibility = 'hidden';
    document.getElementsByClassName("biojs_msa_marker")[0].style.visibility = 'hidden';
  });

}//MSAviewerChangeAlignment


function MSAviewerChangeColor(msa_view) {
  let color = $('#msa_color_select').val();
  msa_view.g.colorscheme.set({"scheme": color});
}//MSAviewerChangeColor


function MSAviewerContract(msa_view) {
  let zoomer_opts = {
    labelFontsize: 6,
    columnWidth: 2,
    residueFont: 1,
  };
  msa_view.g.zoomer.set(zoomer_opts);
}//MSAviewerContract


function MSAviewerExpand(msa_view) {
  let zoomer_opts = {
    labelFontsize: 8,
    columnWidth: 10,
    residueFont: 8,
  };
  msa_view.g.zoomer.set(zoomer_opts);
}//MSAviewerExpand


function MSAviewerLoad(msa_div, pan_gene_file, gm_count, subdir='cds-alignments/') {
  let opts = {
      el: document.getElementById(msa_div),
      conf: {
        importProxy: '',
      },
      //order: {
      //  order: "Identity",
      //},
      vis: {
        conserv: true,
        overviewbox: false,
        labelId: false,
        markers: true,
      },
      zoomer: {
        alignmentHeight: gm_count*15,
        labelFontsize: 6,
        labelNameLength: 130,
        columnWidth: 2,
        residueFont: 1,
        autoResize: true,
      },
      colorscheme: {"scheme": "clustal2"},
      bootstrapMenu: true
  };

  let msa_view = new msa.msa(opts);
  let filename = base_align_url+subdir+pan_gene_file;
  Array.from(document.getElementsByClassName("smenubar")).forEach(function(el) {
    el.style.visibility = 'hidden';
  });
  msa_view.u.file.importURL(filename, function() {
    msa_view.render();
    Array.from(document.getElementsByClassName("biojs_msa_albody")).forEach(function(el) {
      el.style.width = 1048;
    });
    Array.from(document.getElementsByClassName("biojs_msa_marker")).forEach(function(el) {
      el.style.visibility = 'hidden';
    });
    $('#msa_view-download').attr("href", filename);
  });
  
  return msa_view;
}//MSAviewerLoad


function openDetails(ev, elname, datatype, analysis, gm_id) {
  let div = $(ev.target).closest('tr').next().find('div');
  if (ev.target.innerText == '+') {
    // Open
    if (gm_id) {
      document.getElementById(elname).innerHTML = busy;
      populateDataElement(elname, datatype, analysis, gm_id, null);
    }
    else {
      div.html('<br>No details available for this gene model.<br><br>');
    }
    div.css("display", "block");
    ev.target.innerText = '-';
  }
  else {
    // Close
    div.css("display", "none");
    ev.target.innerText = '+';
  }
}//openDetails


function openProteinStructure(ev, elname, analysis, gm_id, trans_id) {
  let el = document.getElementById(elname);
  let id_num = elname.match(/\d+/);
  if (el.style.display == 'block') {
    el.style.display = 'none';
    ev.target.innerText = "+";
  }
  else {
    // Remove any existing WebGL instances
    $('.protein_structure').each(function(el) {
      $(this).html('');
    });
    $('.protein_structure-toggle').each(function() {
      let this_id = $(this).parent().attr('id').match(/\d+/)[0];
      if (this_id != id_num) {
        $(this).html('<b>+</b>');
      }
    });
    el.innerHTML = busy;
    el.style.display = 'block';
    ev.target.innerText = "-";
    populateDataElement(elname, 'protein_structure', analysis, gm_id, trans_id);
  }
}//openProteinStructure


function panGeneAdvSearch(div_name, page) {  
  // Remove simple results, if any
  if (typeof page === 'undefined' || page == 1) {
    $('#term').val('');
    $('#simple_results').empty();
  }
  
  // Show corn-loading icon
  document.getElementById(div_name).innerHTML = "<center><img src='/images/cornloading_trans.gif'></center>";

  let filters = getAdvFilters();

  if (document.getElementById('adv_limit').checked) {
    filters['adv_limit_val'] = document.getElementById("adv_limit_val").value;
  }
  else {
    filters['adv_limit_val'] = 0;
  }
  
  // Prepare this page of data
  filters['pagenum'] = page;
  
  // Results go here
  filters['div_name'] = div_name;
  
  $.ajax({
    method: "POST",
    url: '/search/pan_gene/pan_gene_adv_results.php',
    data: filters,
  }).done(function(contents) {
    $('#'+div_name).html(contents);
  }).fail(function(jqXHR, status) {
    let stop = "here";
  });
}//panGeneAdvSearch


function populateDataElement(elname, datatype, analysis, gm_id, trans_id) {
  if (datatype == 'protein_structure') {
    let gene_model = $('#pan_gene-member_prot'+gm_id).text();
    let transcript = $('#pan_gene-transcript'+trans_id).text(); 
    let annotation = $('#pan_gene-annotation'+gm_id).text();
    $.ajax({
      type: 'POST',
      url: data_url,
      data: {
        "gene_model": gene_model, 
        "gene_model_id": gm_id,
        "transcript": transcript,
        "transcript_id": trans_id, 
        "annotation": annotation, 
        "section": "protein_structure",
        "pan_gene-analysis": analysis},
      cache: true,
    })
    .fail(function() {
      alert("Unable to get data from " + data_url);
    })
    .done(function(content) {
      $('#'+elname).html(content);
    });
  }
  
  else {
    $.ajax({
      type: 'POST',
      url: data_url,
      data: {"gene_model_id": gm_id, "section": "data_details", "dataype": datatype},
      cache: true,
      error : function(xhr, status, error) {
        alert("Unable to get data from " + data_url + ". Status:" + status +", error: " + error);
      },
    })
    .fail(function() {
      alert("Unable to get data from " + data_url);
    })
    .done(function(content) {
      $('#'+elname).html(content);
    });
  }
}//populateDataElement()


// Populate a tab <div>
function populateInnerSection(whichdiv, section, tab, tab_identifier) {
  let pan_gene_name = $('#pan_gene-identifier').val();
  let filter = $('#pan_gene-assembly_set').val();
  let url = '';
  let data = {};
  
  if (tab == 'pan-gene') {
    url = data_url;
    let gene_model = $('#gene_model-identifier').val();
    data = {
      "gene_model": gene_model, 
      "pan_gene": pan_gene_name, 
      "section": section, 
      "filter": filter,
      "pan_gene-analysis": tab_identifier
    };
  }
  else {
    url = locus_data_url;
    let locus = $('#locus'+tab_identifier+'-btn').text();
    if (locus == '') {
      return;  // No locus in tab
    }
    let suspect = false;
    if ($('#locus'+tab_identifier+'-btn').hasClass('red')) {
      suspect = true;
    }
    data = {"locus": locus, "suspect": suspect, "pan_gene_name": pan_gene_name, "section": section};
  }
  let idx = section + '-' + (Object.keys(active_ajax).length + 1);
  let xhr = $.ajax({
    type: 'POST',
    url: url,
    data: data,
    cache: true,
    error : function(xhr, status, error) {
      //alert("Unable to get data from " + data_url + ". Status:" + status +", error: " + error);
    },
  })
  .fail(function(jqXHR, textStatus, errorThrown) {
    if (errorThrown == 'abort') {
      // This was intentional
    }
    if (idx in active_ajax) {
      delete active_ajax[idx];
    }
  })
  .done(function(content) {
    $('#'+whichdiv).html(content);
    if (idx in active_ajax) {
      delete active_ajax[idx];
    }
  });
  
  active_ajax[idx] = xhr;
}//populateInnerSection


function setSectionTab(which) {
  $('.pan_gene-tablink').removeClass('active');
  $('.pan_gene-tablink').addClass('inactive');
  $('.section_tabcontent').hide();
  $('#' + which + '-btn').addClass('active');
  $('#' + which + '-btn').removeClass('inactive');
  $('#' + which + '-tab').show();
}//setRecordTab


function setDownloadExample(elname) {
  example = "Zm00001eb269630\nZm00001eb156130\nZm00001eb124920\nZm00001eb127100\nZm00001eb047750";
  $('#' + elname).val(example);
}//setDownloadExample


function simpleToggle(whichdiv, ev=false) {
  let div = document.getElementById(whichdiv);
  if (div.style.display == 'block') {
    div.style.display = 'none';
    if (ev) {
      ev.currentTarget.text = '+';
    }
  }
  else {
    div.style.display = 'block';
    if (ev) {
      ev.currentTarget.text = '-';
    }
  }
    
  return false;
}//simpleToggle


function stopSectionAjax(section) {
  // Stop any active ajax requests from continuing (and slowing other sections)
  Object.keys(active_ajax).forEach(function(idx) {
    if (idx.includes(section)) {
      active_ajax[idx].abort();  // triggers a fail
    }
  });
}//stopSectionAjax


function toggleInnerSection(whichdiv, section, tab, tab_identifier) {
  let div = document.getElementById(whichdiv);
  if (div.style.display == 'block') {
    div.style.display = 'none';
    stopSectionAjax(section);
    return false;
  }
  else {    
    div.innerHTML = busy;
    div.style.display = 'block';
    populateInnerSection(whichdiv, section, tab, tab_identifier);
  }           
}//toggleInnerSection


function verifyPercent(el) {
  let v = el.value;
  if (isNaN(v)) {
    el.value = '';
  }
  else {
    n = parseInt(v);
    if (n < 0 || n > 100) {
      el.value = '';
    }
  }
  stop = "here";
}//verifyPercent


