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
    var status = document.getElementById('stock-advanced-status');
    if (!pagenum && status) status.textContent = 'Searching stocks with the selected criteria.';
    getAdvSearch("stock", div_name, arrSearchOpt, pagenum);
    if (!pagenum) updateAdvancedStockUrl();
  }
  catch (e) { //This search is being run from shadowbox, so re-use old parameters.
    getAdvSearch("stock", div_name, false, pagenum); 
  }
  return false;
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
var stockGrinRequest = null;
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
   var stockTerm = document.getElementById('stockterm').value.trim();
   var status = document.getElementById('stock-search-status');
   if (!stockTerm) {
     if (status) status.textContent = 'Enter a stock identifier or keyword before searching.';
     document.getElementById('stockterm').focus();
     return;
   }
   if (status) status.textContent = 'Searching MaizeGDB stock records.';
   new_search = true;
   getSearchData('stock', 'stock_results', stockTerm);
   document.getElementById('stock_results').style.display='block'; 
   document.getElementById('grin_results').style.display='none';
   document.getElementById('grin_results_msg').style.display='none';
   document.getElementById('stock_results_msg').style.display='none';
   if (window.history && window.history.replaceState) {
     var searchUrl = new URL(window.location.href);
     searchUrl.searchParams.set('stock_term', stockTerm);
     searchUrl.searchParams.delete('advanced');
     searchUrl.searchParams.set('stock_limit', document.getElementById('limit_val') ? document.getElementById('limit_val').value : '100');
     searchUrl.searchParams.set('stock_pagesize', document.getElementById('pagesize') ? document.getElementById('pagesize').value : '25');
     if (document.getElementById('case') && document.getElementById('case').checked) searchUrl.searchParams.set('stock_case', '1');
     else searchUrl.searchParams.delete('stock_case');
     window.history.replaceState({}, '', searchUrl.pathname + searchUrl.search + searchUrl.hash);
   }
   return false;
}//stock_simple_search


function updateAdvancedStockUrl() {
  var form = document.getElementById('stock-advanced-form');
  if (!form || !window.history || !window.history.replaceState) return;
  var url = new URL(window.location.href);
  Array.from(form.elements).forEach(function(control) {
    if (control.name) url.searchParams.delete(control.name);
  });
  new FormData(form).forEach(function(value, key) {
    url.searchParams.set(key, value);
  });
  url.searchParams.delete('stock_term');
  window.history.replaceState({}, '', url.pathname + url.search + '#advanced-search');
}//updateAdvancedStockUrl


function restoreAdvancedStockSearch() {
  var form = document.getElementById('stock-advanced-form');
  var params = new URLSearchParams(window.location.search);
  if (!form || params.get('advanced') !== '1') return false;
  Array.from(form.elements).forEach(function(control) {
    if (!control.name || !params.has(control.name)) return;
    if (control.type === 'checkbox') control.checked = params.get(control.name) === control.value;
    else if (control.type !== 'hidden') control.value = params.get(control.name);
  });
  stock_adv_search('adv_results');
  return true;
}//restoreAdvancedStockSearch


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
  if (stockGrinRequest && stockGrinRequest.readyState !== 4) {
    stockGrinRequest.abort();
  }
  stockGrinRequest = $.ajax({
    type: "POST",
    url: url,
    data: post_data,
    cache: true,
    beforeSend: function() {
      document.getElementById(div_name).innerHTML = "<div class='stock-loading'><img src='/images/cornloading_trans.gif' alt='Loading search results'></div>";
    }
  })
    .done(function(data) {
      if (data.match(/^\s*javascript:/) != null) {
        var redirect = data.match(/^\s*javascript:(?:document\.)?location\s*=\s*['"]([^'"]+)['"]\s*;?\s*$/);
        if (redirect && redirect[1].indexOf('/data_center/stock') === 0) {
          window.location.assign(redirect[1]);
        }
        else {
          document.getElementById(div_name).textContent = 'The result could not be opened safely. Please refine the search.';
        }
      }
      else {
        $('#'+div_name).html(data);
      }
    });
}//getGrinSearchData()


function initializeStockSearchPage() {
  var page = document.querySelector('.stock-page');
  if (!page) return;

  var exampleButton = page.querySelector('[data-stock-example]');
  if (exampleButton) {
    exampleButton.addEventListener('click', function() {
      var input = document.getElementById('stockterm');
      input.value = exampleButton.getAttribute('data-stock-example');
      input.focus();
    });
  }

  page.querySelectorAll('[data-check]').forEach(function(control) {
    control.addEventListener('change', function() {
      var checkbox = document.getElementById(control.getAttribute('data-check'));
      if (checkbox) checkbox.checked = true;
    });
  });

  var quickForm = document.getElementById('stock-quick-form');
  if (quickForm) {
    var quickTerm = document.getElementById('stockterm');
    if (quickTerm) {
      quickTerm.addEventListener('keydown', function(event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        quickForm.requestSubmit();
      }, true);
    }
    quickForm.addEventListener('submit', function(event) {
      var term = document.getElementById('stockterm');
      if (!term || !term.value.trim()) {
        event.preventDefault();
        if (status) status.textContent = 'Enter a stock identifier or keyword before searching.';
        if (term) term.focus();
        return;
      }
      document.getElementById('stock-limit-state').value = document.getElementById('limit_val') ? document.getElementById('limit_val').value : '';
      document.getElementById('stock-pagesize-state').value = document.getElementById('pagesize') ? document.getElementById('pagesize').value : '';
      document.getElementById('stock-case-state').value = document.getElementById('case') && document.getElementById('case').checked ? '1' : '';
    });
  }

  var advancedForm = document.getElementById('stock-advanced-form');
  if (advancedForm) {
    advancedForm.addEventListener('submit', function(event) {
      var selected = advancedForm.querySelector('fieldset input[type="checkbox"]:checked');
      if (!selected) {
        event.preventDefault();
        var advancedStatus = document.getElementById('stock-advanced-status');
        if (advancedStatus) advancedStatus.textContent = 'Select at least one advanced-search criterion.';
      }
    });
    advancedForm.addEventListener('reset', function() {
      window.setTimeout(function() {
        var url = new URL(window.location.href);
        Array.from(advancedForm.elements).forEach(function(control) {
          if (control.name) url.searchParams.delete(control.name);
        });
        window.history.replaceState({}, '', url.pathname + url.search + '#advanced-search');
        var results = document.getElementById('adv_results');
        var advancedStatus = document.getElementById('stock-advanced-status');
        if (results) results.innerHTML = '';
        if (advancedStatus) advancedStatus.textContent = 'Advanced search reset.';
      }, 0);
    });
  }

  page.querySelectorAll('[data-stock-results-mode]').forEach(function(button) {
    button.addEventListener('click', function() {
      var input = document.getElementById('stockterm');
      toggle_grin(input ? input.value : '', button.getAttribute('data-stock-results-mode'));
    });
  });

  var results = document.getElementById('stock_results');
  var status = document.getElementById('stock-search-status');
  var advancedLimit = document.getElementById('adv_limit');
  if (advancedLimit && !advancedLimit.getAttribute('aria-label')) {
    advancedLimit.setAttribute('aria-label', 'Enable advanced search result limit');
  }
  var simpleLimitValue = document.getElementById('limit_val');
  if (simpleLimitValue) simpleLimitValue.setAttribute('aria-label', 'Maximum number of quick-search results');
  var pageSize = document.getElementById('pagesize');
  if (pageSize) pageSize.setAttribute('aria-label', 'Quick-search records per page');
  if (results && status && window.MutationObserver) {
    new MutationObserver(function() {
      labelStockResultImages(results);
      if (results.textContent.trim()) status.textContent = 'Stock search results updated.';
    }).observe(results, {childList: true, subtree: true});
  }

  var advancedResults = document.getElementById('adv_results');
  var advancedStatus = document.getElementById('stock-advanced-status');
  if (advancedResults && advancedStatus && window.MutationObserver) {
    new MutationObserver(function() {
      labelStockResultImages(advancedResults);
      if (advancedResults.textContent.trim()) advancedStatus.textContent = 'Advanced stock search results updated.';
    }).observe(advancedResults, {childList: true, subtree: true});
  }

  initializeStockTypeChart();
  restoreAdvancedStockSearch();
}//initializeStockSearchPage


function labelStockResultImages(container) {
  container.querySelectorAll('img:not([alt])').forEach(function(image) {
    var source = image.getAttribute('src') || '';
    if (source.indexOf('corn-icon-prev') >= 0) image.setAttribute('alt', 'Previous results page');
    else if (source.indexOf('corn-icon-next') >= 0) image.setAttribute('alt', 'Next results page');
    else if (source.indexOf('cornloading_trans') >= 0) image.setAttribute('alt', 'Loading search results');
    else image.setAttribute('alt', '');
  });
}//labelStockResultImages


function initializeStockTypeChart() {
  var target = document.getElementById('stock-type-chart');
  if (!target) return;
  if (!window.IntersectionObserver) {
    loadStockPlotly();
    return;
  }
  var observer = new IntersectionObserver(function(entries) {
    if (!entries.some(function(entry) { return entry.isIntersecting; })) return;
    observer.disconnect();
    loadStockPlotly();
  }, {rootMargin: '240px'});
  observer.observe(target);
}//initializeStockTypeChart


function loadStockPlotly() {
  if (window.Plotly) {
    renderStockTypeChart();
    return;
  }
  var script = document.createElement('script');
  script.src = '/js/lib/plotly/plotly-2.25.2.min.js';
  script.async = true;
  script.onload = renderStockTypeChart;
  document.head.appendChild(script);
}//loadStockPlotly


function renderStockTypeChart() {
  var target = document.getElementById('stock-type-chart');
  if (!target || !window.Plotly || target.getAttribute('data-plotted') === 'true') return;
  var values;
  try {
    values = JSON.parse(target.getAttribute('data-stock-types') || '{}');
  }
  catch (error) {
    return;
  }
  if (!values.labels || !values.labels.length) return;

  target.setAttribute('data-plotted', 'true');
  Plotly.newPlot(target, [{
    type: 'bar',
    orientation: 'h',
    y: values.labels.slice().reverse(),
    x: values.values.slice().reverse(),
    marker: {color: '#315f35'},
    hovertemplate: '%{y}: %{x:,} records<extra></extra>'
  }], {
    margin: {l: 150, r: 20, t: 10, b: 45},
    paper_bgcolor: 'rgba(0,0,0,0)',
    plot_bgcolor: 'rgba(0,0,0,0)',
    font: {family: 'Inter, Segoe UI, Arial, sans-serif', color: '#28362d', size: 12},
    xaxis: {title: 'Active stock records', gridcolor: '#dfe5dc', rangemode: 'tozero'},
    yaxis: {automargin: true}
  }, {responsive: true, displayModeBar: false});
}//renderStockTypeChart


if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeStockSearchPage);
}
else {
  initializeStockSearchPage();
}


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
