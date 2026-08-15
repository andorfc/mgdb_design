/**
* Toggles images of related data section
*/
var opened_carousel = "";
function toggle_images(div_id, img_id, img_name, first_name) {      
  if (opened_carousel != "")
    document.getElementById("tbl" + opened_carousel).style.display = "none";
  else
    document.getElementById("tbl" + first_name).style.display = "none";    
    
  document.getElementById(div_id).style.display = "block";
  document.getElementById("tbl" + img_name).style.display = "table-footer-group";    
  opened_carousel = img_name;
  document.getElementById('carousel').src = "/tools/car/examples/stocks_carousel.php?id=" + img_id + "&name=" + escape(img_name);
}//toggle_images


var current_tab = "first_tab"; 
function toggle_tab(name) {
  if (current_tab != name) {    
    $('#section_'+name).removeClass('stock_tab_unfocus');
    $('#section_'+name).addClass('stock_tab_focus');
  
    $('#section_'+current_tab).removeClass('stock_tab_focus');
    $('#section_'+current_tab).addClass('stock_tab_unfocus');
    current_tab = name;    
  }
}//toggle_tab


function refresh_imgtbl(id, img_name) {      
  var arrImg = new Array();
  arrImg["name"] = img_name;
  
  getDataParameters("stock", "related_records", id, arrImg);
}//refresh_imgtbl
  
/**
* Performs an advanced search
*/
function stock_adv_search(div_name, pagenum) {  
  try {
    var arrSearchOpt = {};
    if(document.getElementById("stock_name").value == "") 
      document.getElementById("box_name").checked = false;

    //Add all checkbox params to array
    arrSearchOpt["box_mgsc"] = document.getElementById("box_mgsc").checked;
    arrSearchOpt["box_dev"] = document.getElementById("box_dev").checked;
    arrSearchOpt["box_name"] = document.getElementById("box_name").checked;
    arrSearchOpt["box_type"] = document.getElementById("box_type").checked;
    arrSearchOpt["box_lg"] = document.getElementById("box_lg").checked;
    arrSearchOpt["box_genvar1"] = document.getElementById("box_genvar1").checked;
    arrSearchOpt["box_genvar2"] = document.getElementById("box_genvar2").checked;
    arrSearchOpt["box_genvar3"] = document.getElementById("box_genvar3").checked;
    arrSearchOpt["box_kv"] = document.getElementById("box_kv").checked;
    arrSearchOpt["box_pheno"] = document.getElementById("box_pheno").checked;
    arrSearchOpt["box_avail"] = document.getElementById("box_avail").checked;
    arrSearchOpt["box_parent"] = document.getElementById("box_parent").checked;
    arrSearchOpt["box_expvp"] = document.getElementById("box_expvp").checked;
    arrSearchOpt["box_bank"] = document.getElementById("box_bank").checked;
    
    //Add all select values to array
    arrSearchOpt["dev"] = document.getElementById("dev").value; 
    arrSearchOpt["stock_name"] = document.getElementById("stock_name").value; 
    arrSearchOpt["type"] = document.getElementById("type").value; 
    arrSearchOpt["lg"] = document.getElementById("lg").value; 
    arrSearchOpt["genvar1"] = document.getElementById("genvar1").value;
    arrSearchOpt["genvar2"] = document.getElementById("genvar2").value; 
    arrSearchOpt["genvar3"] = document.getElementById("genvar3").value;     
    
    arrSearchOpt["kv"] = document.getElementById("kv").value; 
    arrSearchOpt["pheno"] = document.getElementById("pheno").value;
    arrSearchOpt["phenovar"] = document.getElementById("phenovar").value; 
    arrSearchOpt["avail"] = document.getElementById("avail").value;
    arrSearchOpt["parent"] = document.getElementById("parent").value; 

    if(document.getElementById("adv_limit").checked) {
      arrSearchOpt["adv_limit_val"] = document.getElementById("adv_limit_val").value;
    }
    else {
      arrSearchOpt["adv_limit_val"] = 0;
    }
    getAdvSearch("stock", div_name, arrSearchOpt, pagenum);
  }
  catch (e) { //This search is being run from shadowbox, so re-use old parameters.
    getAdvSearch("stock", div_name, false, pagenum); 
  }
}//stock_adv_search


function toggle_references(display) {   
  if(display == "show") {
    document.getElementById("show_ref").style.display = "block";
    document.getElementById("hide_ref").style.display = "none";
  }
  else {
    document.getElementById("show_ref").style.display = "none";
    document.getElementById("hide_ref").style.display = "block";
  }
}//toggle_references


var grinCount;
var stockCount;
/**
 * Switch between stock/grin results
 */
function toggle_grin(term, mode, new_search=true) { 
  var pages_loaded; 
  if (mode == "grin") {
    if (!new_search) {
     toggle_grin_message(stockCount, "stock", false); 
    }
    else { 
     getGrinSearchData("grin", "grin_results", term);
     new_search = false; 
    }
    
    document.getElementById("stock_results").style.display = "none";
    document.getElementById("grin_results").style.display = "block";           
  }
  else {
     document.getElementById("stock_results").style.display = "block";
     document.getElementById("grin_results").style.display = "none";
     toggle_grin_message(grinCount, "grin", false);
  }         
}//toggle_grin


function toggle_grin_message(count, mode, new_results) { 
  new_search = new_results;
  if (mode == "grin"){
    document.getElementById('grin_count').innerHTML = count;
    document.getElementById('grin_results_msg').style.display = 'block';
    document.getElementById('stock_results_msg').style.display = 'none';
    grinCount = count;    
  }
  else {       
    document.getElementById('stock_count').innerHTML = count;
    document.getElementById('stock_results_msg').style.display = 'block';
    document.getElementById('grin_results_msg').style.display = 'none';
    stockCount = count;     
  }  
}//toggle_grin_message


/**
 * Called when entering a new search on stock page
 */
function stock_simple_search() {
   new_search = true;
   getSearchData('stock', 'stock_results', document.getElementById('stockterm').value);
   document.getElementById('stock_results').style.display='block'; 
   document.getElementById('grin_results').style.display='none';
   document.getElementById('grin_results_msg').style.display='none';
   document.getElementById('stock_results_msg').style.display='none';
}//stock_simple_search


/**
 * jp - runs grin search.
 *      Use this for grin on the stock page because it avoids using the same xmlhttp object
 *      when stock/grin searches are being run simultaneously (this was causing issues).
 */
function getGrinSearchData(source_name, div_name, id_val, pagenum) {
  
  if (div_name == "")
    return;
    
  else if (div_name == "main_search" || div_name == "main_results") {
    document.getElementById("main_loading").style.display = "block";
    document.getElementById("search_box").style.visibility = "hidden";
    document.getElementsByTagName("body")[0].style.overflow = "hidden";
  }
  
  //Search limits are now set by user, where 100 is the default.
  var search_limit = 0;
  if(document.getElementById("limit") != undefined){  
    var limit_box = document.getElementById("limit").checked; 
    if (limit_box) { 
      search_limit = document.getElementById("limit_val").value; 
      }
  }
  
  var url = "/search/stock/" + source_name + "_results.php";
  var post_data = "term=" + encodeURI(id_val) + "&search_limit=" + search_limit;
  if (document.getElementById('case') != null) {
    if (document.getElementById('case').checked) {
      post_data += "&case=true";
    }
  }        
                                                
  if (div_name == "main_search")
    post_data += "&main=true"; //Search is running from main search box
  else if (div_name == "main_results") 
    post_data += "&main=initial_true";
    
  if (typeof pagenum == 'number' && pagenum > 0) {
    post_data += "&pagenum=" + pagenum;
    var grinRows = document.getElementById("grin_results_rows");
    if (grinRows != null) {
      post_data += "&rows=" + grinRows.value;
    }
  }
  post_data += "&div_name=grin_results";
  $.ajax({
    type: "POST",
    url: url,
    data: post_data,
    cache: true,
    beforeSend: function() {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
  })
    .done(function(data) {
      if (data.match(/^\s*javascript:/) != null) {
        // This is a javascript command; execute it here since it won't execute
        //   automatically when div is filled.
        var cmd = data.replace('javascript:', '');
        eval(cmd);
      }
      else {
        $('#'+div_name).html(data);
      }
    });
}//getGrinSearchData()


/**
 * Toggles display of Trait Values for IBM and NAM lines (in Related record section for NAM stocks)
 */
var new_trait_search = true;
var arrSearchOpt = new Array();
function trait_val_search(stock_name, div_name) {  
  var icon = document.getElementById("trait_val_icon"); 
  //add additional params to array such as locus or probe ids
  if (icon.src.indexOf("expand") > -1) {
    if (new_trait_search) {
      arrSearchOpt["adv_limit_val"] = 5000;
      arrSearchOpt["box_stock"] = true;      
      arrSearchOpt["stock"] = stock_name;
      arrSearchOpt["dc_page"] = "stock";
      getAdvSearch("traits_ibm_nam", div_name, arrSearchOpt);
      new_trait_search = false;
    }
    document.getElementById("trait_val_icon").src = "/images/collapse.jpg";
    document.getElementById("trait_val_results").style.display = "block";
    document.getElementById("trait_vals").style.width = '1000px';
  }
  else {
    document.getElementById("trait_val_icon").src = "/images/expand.jpg";
    document.getElementById("trait_val_results").style.display = "none";
    document.getElementById("trait_vals").style.width = '';
  }
}//trait_val_search


function traits_ibm_nam_adv_search(div_name, pagenum) {
  getAdvSearch("traits_ibm_nam", div_name, arrSearchOpt, pagenum);
}//traits_ibm_nam_adv_search

