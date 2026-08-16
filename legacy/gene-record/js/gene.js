// file: gene.js
//
// purpose: javascript for gene center pages


// Not currently in use, but maybe later
//function bulkMarkerSearch() {
//  debugger;
//  if ($('#bulk_markers_distance').val() == '') {
//    msg = "No distance given.";
//    alert(msg);
//    return;
//  }
//  if ($('#bulk_markers').val()  == '') {
//    msg = "No markers provided."
//    alert(msg);
//    return;
//  }
//  if ($('#bulk_markers_assembly').val() == '') {
//    msg = "No assembly selected.";
//    alert(msg);
//    return
//  }
//  
//  $('#bulk_marker_form').submit();
//}//bulkMarkerSearch


function bulkPositionSearch() {
  if ($('#bulk_positions').val()  == '') {
    msg = "No positions provided."
    alert(msg);
    return;
  }
  if ($('#bulk_position_assembly').val()  == '') {
    msg = "No assembly selected."
    alert(msg);
    return;
  }
  
  $('#bulk_position_form').submit();
}//bulkPositionSearch


function bulkScoreSearch() {
  if ($('#bulk_score_gms').val()  == '') {
    msg = "No gene models provided."
    alert(msg);
    return;
  }
  
  $('#gm_score_form').submit();
}//bulkScoreSearch


function chrPosSearch() {
  var srb = $("input[name='region']:radio:checked").attr('id');
  if (srb == 'assembly_coords') {
    if ($("#start_pos").val() != '' || $("#end_pos").val() != '') {
      if ($("#start_pos").val() == '' || $("#end_pos").val() == '') {
        msg = "You need to set a start and end position or leave both fields ";
        msg += "blank to get genes/gene models for the entire chromosome."
        alert(msg);
        return;
      }
      else if (parseInt($("#start_pos").val()) > parseInt($("#end_pos").val())) {
        msg = "The ending coordinate is smaller than the starting coordinate.";
        alert(msg);
        return;
      }
    }
  }
  else if (srb == 'marker_pos') {
    if ($("#start_marker").val() == '' && $("#end_marker").val() == '') {
      msg = "You need to enter at least one marker or BAC name.";
      alert(msg);
      return;
    }
  }
  
  $("#region_form").submit();
}//chrPosSearch


function downloadSequence(el, dbtype) {
  // Create a form that will send the data off to the script to execute the BLAST command
  let sendform = $("<form/>").attr('id', 'download_sequence_form')
                             .attr('action', 'https://sequence2.maizegdb.org/get_sequence.php')
                             .attr('method', 'post')
                             .attr('target', '_blank')
  $("body").append(sendform);

  sendform.append($("<input />").attr('type', 'hidden')
                                .attr('name', 'dbtype')
                                .attr('value', dbtype));

  el.parent().children('input').each(function() {
    sendform.append($("<input />").attr('type', 'hidden')
                                  .attr('name', $(this).attr('id'))
                                  .attr('value', $(this).val()));
  });
  
  sendform.submit();
}//downloadSequence


function geneAdvSearch(div_name, pagenum) {  
  try {
    var arrSearchOpt = {};

    // Add all checkbox params to array
    arrSearchOpt["box_version"] = document.getElementById("box_version").checked;
    arrSearchOpt["box_type"] = document.getElementById("box_type").checked;
    arrSearchOpt["box_chr"] = document.getElementById("box_chr").checked;
    arrSearchOpt["box_range"] = document.getElementById("box_range").checked;
    arrSearchOpt["box_locus_assoc"] = document.getElementById("box_locus_assoc").checked;    
    arrSearchOpt["box_gp"] = document.getElementById("box_gp").checked;
    arrSearchOpt["box_pheno"] = document.getElementById("box_pheno").checked;
    arrSearchOpt["box_protein"] = document.getElementById("box_protein").checked;
    arrSearchOpt["box_trait"] = document.getElementById("box_trait").checked;
    arrSearchOpt["box_tandem"] = document.getElementById("box_tandem").checked;

    // Add all select values to array
    arrSearchOpt["version"] = encodeURIComponent(document.getElementById("version").value); 
    arrSearchOpt["type"] = document.getElementById("type").value; 
    arrSearchOpt["chromosome"] = document.getElementById("chromosome").value; 
    arrSearchOpt["range_start"] = document.getElementById("range_start").value;
    arrSearchOpt["range_end"] = document.getElementById("range_end").value;
    arrSearchOpt["gene_product"] = document.getElementById("gene_product").value; 
    arrSearchOpt["pheno"] = document.getElementById("pheno").value; 
    arrSearchOpt["protein"] = document.getElementById("protein").value; 
    arrSearchOpt["trait"] = document.getElementById("trait").value; 

    if (document.getElementById("adv_limit").checked) {
      arrSearchOpt["adv_limit_val"] = document.getElementById("adv_limit_val").value;
    }
    else {
      arrSearchOpt["adv_limit_val"] = 0;
    }
    
    getAdvSearch("gene", div_name, arrSearchOpt, pagenum);
  }
  catch (e) { //This search is being run from shadowbox, so re-use old parameters.
    getAdvSearch("gene", div_name, false, pagenum); 
  }
}//geneAdvSearch


var prev_out_type = "";
// Download By Region
function setRegion(which) {
  var srb = $("input[name='region']:radio:checked");
  if (which == 'assembly' || (srb && srb.attr("id") == "assembly_coords")) {
    $('#assembly_coords').prop('checked', true);
    $('#region_form').attr('method', 'get');
    $('#region_form').attr('action', '/search/gene/gene_chr_position.php');
    $('#gm_version').prop('disabled', false);
    $('#gm_pos_out_type').prop('disabled', true);
//    $('#out_type').prop('disabled', false);
//    $('#out_type').val(prev_out_type);
    $('.assembly_coords_els').removeClass("disabled_form_set");
    $('.marker_pos_els').addClass("disabled_form_set");
    $('.gm_pos_els').addClass("disabled_form_set");
    $('input[class="assembly_coords_els"]').prop('disabled', false);
    $('input[class="marker_pos_els"]').prop('disabled', true);
    $('input[class="gm_pos_els"]').prop('disabled', true);
  }
  else if (srb.attr("id") == "gm_pos") {
    $('#region_form').attr('action', '/search/gene/gene_gm_position.php');
    $('#region_form').attr('method', 'post');
    prev_out_type = $('#out_type option:selected').val();
    $('#gm_version').prop('disabled', true);
//    $('#out_type').val('details');
//    $('#out_type').prop('disabled', true);
    $('#gm_pos_out_type').prop('disabled', false);
    $('.assembly_coords_els').addClass("disabled_form_set");
    $('.marker_pos_els').addClass("disabled_form_set");
    $('.gm_pos_els').removeClass("disabled_form_set");
    $('input[class="assembly_coords_els"]').prop('disabled', true);
    $('input[class="marker_pos_els"]').prop('disabled', true);
    $('input[class="gm_pos_els"]').prop('disabled', false);

  }
  else {
    $('#region_form').attr('action', '/search/gene/gene_marker_position.php');
    $('#region_form').attr('method', 'get');
    $('#gm_version').prop('disabled', true);
    $('#gm_pos_out_type').prop('disabled', true);
//    $('#out_type').prop('disabled', false);
//    $('#out_type').val(prev_out_type);
    $('.assembly_coords_els').addClass("disabled_form_set");
    $('.marker_pos_els').removeClass("disabled_form_set");
    $('.gm_pos_els').addClass("disabled_form_set");
    $('input[class="assembly_coords_els"]').prop('disabled', true);
    $('input[class="marker_pos_els"]').prop('disabled', false);
    $('input[class="gm_pos_els"]').prop('disabled', true);
  }
}//setRegion


// Convenience function for debugging, pairs with search/gene/get_fasta.php
//  (shows output in div rather than having to open downloaded file)
function getFASTA() {
  var url = '/search/gene/get_fasta.php';
  $.post(url,
         {'gm_list'        : $('#gm_form').find('#gm_list').val(),
          'in_type'        : $('#gm_form').find('#in_type').val(),
          'out_type'       : $('#gm_form').find('#out_type').val(),
          'model_type'     : $('#gm_form').find('#model_type').val(),
          'gene_model_set' : $('#gm_form').find('#gene-model-set').val()
         },
         function(data, status) {
           $('#sequence-results').html(data);
           $('#sequence-results').show();
        });
}//getFASTA


// Convenience function for debugging search/gene/get_details.php
//  (shows output in div rather than having to open downloaded file)
function getDetails() {
  var url = '/search/gene/get_details.php';
  $.post(url,
         {'gm_list'        : $('#gm_details_form').find('#gm_details_list').val(),
          'out_type'       : $('#gm_details_form').find('#out_type').val(),
          'gene_model_set' : $('#gm_details_form').find('#gene-model-set').val()
         },
         function(data, status) {
           $('#details-results').html(data);
           $('#details-results').show();
        });
}//getDetails


function setRecordTab(which) {
  if (which == 'locus') {
    document.getElementById('gene_tab').style.display='inline';
    document.getElementById('pangenome_tab').style.display='none';
    document.getElementById('sequence_tab').style.display='none';
    document.getElementById('gm_tab').style.display='none';
    
    document.getElementById('gene_menu_list').style.display='inline';
    document.getElementById('pangenome_menu_list').style.display='none';
    document.getElementById('sequence_menu_list').style.display='none';
    document.getElementById('gm_menu_list').style.display='none';
    
    //RI-1839: refresh image carousel
    var carousel = document.getElementById('carousel');
    if (carousel != null) {
        carousel.contentWindow.location.reload();
    }
  }
  else if (which == 'gm') {
    document.getElementById('gene_tab').style.display='none';
    document.getElementById('pangenome_tab').style.display='none';
    document.getElementById('sequence_tab').style.display='none';
    document.getElementById('gm_tab').style.display='inline';
    
    document.getElementById('gene_menu_list').style.display='none';
    document.getElementById('pangenome_menu_list').style.display='none';
    document.getElementById('sequence_menu_list').style.display='none';
    document.getElementById('gm_menu_list').style.display='inline';
  }
  else if (which == 'sequence') {
    document.getElementById('gene_tab').style.display='none';
    document.getElementById('pangenome_tab').style.display='none';
    document.getElementById('sequence_tab').style.display='inline';
    document.getElementById('gm_tab').style.display='none';
    
    document.getElementById('gene_menu_list').style.display='none';
    document.getElementById('pangenome_menu_list').style.display='none';
    document.getElementById('sequence_menu_list').style.display='inline';
    document.getElementById('gm_menu_list').style.display='none';
  }    
  else if (which == 'pangenome') {
    document.getElementById('gene_tab').style.display='none';
    document.getElementById('pangenome_tab').style.display='inline';
    document.getElementById('sequence_tab').style.display='none';
    document.getElementById('gm_tab').style.display='none';
    
    document.getElementById('gene_menu_list').style.display='none';
    document.getElementById('pangenome_menu_list').style.display='inline';
    document.getElementById('sequence_menu_list').style.display='none';
    document.getElementById('gm_menu_list').style.display='none';
  }    
}//setRecordTab


/**
  jp - Controls stock field in overview section of Gene record.
 */
function toggle_stocks(){
 var stock_div = document.getElementById("stock_list");
 var stock_text = document.getElementById("stock_text");
 if (stock_div.style.display == "none"){
   stock_div.style.display = "inline";
   stock_text.innerHTML = "hide";
 }
 else {
   stock_div.style.display = "none";
   stock_text.innerHTML = "show";
 }
}


/**
 jp - toggle descriptions in expression section
 */
function toggleDesc(div) {
  var divDisp = document.getElementById(div).style.display;
  document.getElementById(div).style.display = (divDisp == 'none') ? 'block' : 'none';
}


/**
 * jp - Controls display of Gel Pattern Images inside the Genetic Info section
 */
var new_gel_search = true;
function gelImgSearch(div_name, adl_params) {
  var arrSearchOpt = new Array();
  var icon = document.getElementById("gel_img_icon");
  //add additional params to array such as locus or probe ids
  if (icon.src.indexOf("expand") > -1) {
    if (new_gel_search) {
      var arr = adl_params.split("=");
      if (arr.length % 2 == 0) { //should always be an even number of elements
        for (i=0; i<arr.length; i+=2) {
          var key = arr[i];
          var val = arr[i+1];
          arrSearchOpt[key] = val;
        }
      }
      getAdvSearch("image_gel_pattern", div_name, arrSearchOpt); //TODO: include pagenum as a 4th param?
      new_gel_search = false;
    }
    document.getElementById("gel_img_icon").src = "/images/collapse.jpg";
    document.getElementById("gel_img_results").style.display = "block";
  }
  else {
    document.getElementById("gel_img_icon").src = "/images/expand.jpg";
    document.getElementById("gel_img_results").style.display = "none";
  }
}
