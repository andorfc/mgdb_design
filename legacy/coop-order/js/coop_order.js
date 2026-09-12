// file: coop_order.js
//
// purpose: Javascript support for the Coop order page.
//
// history:
//  08/01/22  eksc  created

var heading = 'Your stock list:';
var no_stock_heading = 'Your stock list is empty';
var subdomain = '.maizegdb.org';

$(window).load(function () {
  //subdomain = '.' + location.host;
  
  // Set click action for popup close button
  $('.coop_stock_popup_close').click(function() {
    $('.coop_stock_popup').hide();
  });
  
  // Fill box with any existing stocks on the order list
  coop_stock_orderGetOrder(true, 'coop_stock_orderUpdateStockList');
});//window load


function coop_stock_orderAddToList(stock_name, stock_comment) {
  // Remove any CRs from the comment
  stock_comment = stock_comment.replaceAll("\n", ' ');
  
  stock_name = encodeURI(stock_name);  // This does not encode '+';
  stock_name = stock_name.replace('+', '%2B');
  let data = {
    "action": "add-stock", 
    "stock_name": stock_name, 
    "stock_comment": stock_comment};
  $.ajax({
    type: "POST",
    url: '/ordering/coop_order',
    data: data,
    cache: true,
  })
  .fail(function(data) {
    alert("Unable to add stock '"+stock_name+"' due to " + data);
  })
  .done(function(data) {
    if (data == 'ERROR') {
      msg = "Unable to save stock request! Please notify MaizeGDB personnel ";
      msg += "and send your stock order directly to maize@uiuc.edu. ";
      msg += "We apologize for the inconvience.";
      alert(msg);
    }
    if ($('#coop_stock_order-select option[value="'+stock_name+'"]').length == 0) {
      coop_stock_orderGetOrder(true, 'coop_stock_orderUpdateStockList');
    }
    else if ($('#coop_stock_order-edit_btn').val() == 'Add to list') {
      msg = "This stock is already included on your list.\n\nIf you want this stock ";
      msg += "in multiple backgrounds, please include all backgrounds in the comments ";
      msg += "field.\n\nYou can change the comment for a stock by clicking the ";
      msg += "'add/edit comment' button after selecting a stock from the list.";
      alert(msg);
    }
    else {
      // Update select from information'
      coop_stock_orderGetOrder(true, 'coop_stock_orderUpdateStockList') ;
    }
    $('#coop_stock_order-edit_btn').val('Add to list');
  });
}//coop_stock_orderAddToList


function coop_orderCheckCountry() {
  var country = $('#coop_stock_order-country').val();
  if (country == '') {
    return;
  }
  
  let data = {"action": "check-country", "country": country};
  $.ajax({
    type: "POST",
    url: '/ordering/coop_order',
    data: data,
    cache: true,
  })
  .fail(function(data) {
    let msg = "Unable to find country '" + country
            + ".' If this country is correct, press 'OK.'";
    if (!confirm(msg)) {
      $('#coop_stock_order-country').val();
    }
  })
  .done(function(data) {
    if (data == '') {
      let msg = "Unable to find country '" + country
              + ".' If this country is correct, press 'OK.'";
      if (!confirm(msg)) {
        $('#coop_stock_order-country').val('');
      }
    }
    else if (data.startsWith('Multiple:')) {
      let msg = "Multiple countries have names similar to '" + country;
      msg += ".' Please type a specific country name. ";
      msg += "Or, if this country name is correct, press 'OK.'";
      if (!confirm(msg)) {
        $('#coop_stock_order-country').val('');
      }
    }
    else if (data != country) {
      $('#coop_stock_order-country').val(data);
    }
  });
}//coop_orderCheckCountry


function coop_stock_orderCheckStock() {
  var stock_name = $('#coop_stock_order-stock_name').val();
  if (stock_name == '') {
    alert("No stock name entered.");
    return;
  }
  
/*
  if ($('#coop_stock_order-select option').length > 20) {
    let msg = "Please limit stock orders to no more than 20 stocks. ";
    msg +=  "If you have more stocks to order, please submit this order, ";
    msg += "then start a new request.";
    alert(msg);
  }
*/
  
  let data = {"action": "check-stock", "stock_name": stock_name};
  $.ajax({
    type: "POST",
    url: '/ordering/coop_order',
    data: data,
    cache: true,
  })
  .fail(function(data) {
    alert("Unable to find stock '"+stock_name+"'");
    $('#coop_stock_order-stock_name').val()
  })
  .done(function(data) {
    if (data.startsWith('Multiple: ')) {
      data = data.replace('Multiple: ', '');
      let stocks = data.split('||');
      if (stocks.length > 20) {
        let html = "Many stock names contain '" + stock_name + "'. ";
        html += "Please use the <a href='/stock_catalog' target='_blank'>stock catalog</a> ";
        html += "or <a href='/data_center/stock' target='_blank'>stock search page</a> ";
        html += "to find the specific stock name.";
        $('#coop_stock_popup_content').html(html); 
        $('.coop_stock_popup').show();
      }
      else {
        let html = "Multiple stocks have names similar to '" + stock_name + "'. ";
        html += "Please use one of the names below, or ";
        html += "use the <a href='/stock_catalog' target='_blank'>stock catalog</a> ";
        html += "or <a href='/data_center/stock' target='_blank'>stock search page</a> ";
        html += "to find the specific stock name.<br><br>";
        html += coop_stock_orderMakeLinks(stocks);
        $('#coop_stock_popup_content').html(html); 
        $('.coop_stock_popup').show();
      }
    }
    else if (data.startsWith('Stock')) {  // error message returned
      alert(data);
    }
    else {
      $('#coop_stock_order-none').text(heading);
      coop_stock_orderAddToList(data, $('#coop_stock_order-stock_comment').val());
      $('#coop_stock_order-stock_name').val('');
      $('#coop_stock_order-stock_comment').val('');
    }
  });
}//coop_stock_orderCheckStock


function coop_stock_orderClearList() {
  let data = {"action": "clear-order"};
  $.ajax({
    type: "POST",
    url: '/ordering/coop_order',
    data: data,
    cache: true,
  })
  .fail(function(data) {
    alert("Unable to clear order due to " + data);
  })
  .done(function(data) {
    $('#coop_stock_order-select').empty();
    $('#coop_stock_order-none').text(no_stock_heading);
    document.cookie = "stock_order=empty;path=/;domain=" + subdomain + ";expires=Sat, 1 Jan 1980 12:00:00 UTC";
  });
}//coop_stock_orderClearList


function coop_stock_orderEnableStockButtons(enable) {
  if (enable) {
    $('#coop_stock_order-view').removeAttr('disabled');
    $('#coop_stock_order-comment').removeAttr('disabled');
    $('#coop_stock_order-remove').removeAttr('disabled');
  }
  else {
    $('#coop_stock_order-view').attr('disabled','disabled');
    $('#coop_stock_order-comment').attr('disabled', 'disabled');
    $('#coop_stock_order-remove').attr('disabled','disabled');
  }
}//coop_stock_orderEnableStockButtons


function coop_stock_orderGetDescriptiveName() {
  return $('#coop_stock_order-select').find(":selected").text().replace(/\s+\[.*\]/, '');
}//coop_stock_orderGetDescriptiveName


function coop_stock_orderGetOrder(w_comments, callback) {
  let data = {"action": "get-list"};
  $.ajax({
    type: "POST",
    url: '/ordering/coop_order',
    data: data,
    cache: true,
  })
  .fail(function(data) {
    alert("Unable to get stock list.");
    $('#coop_stock_order-select').val();
  })
  .done(function(data) {
    if (data == 'ERROR') {
      return [];
    }
    
    let stocks = [];
    data.split(':::').forEach(function(str) {
      let parts = str.split('|||');
      if (w_comments && parts.length > 1) {
        stocks.push(parts[0] + '   [' + parts[1] + ']');
      }
      else {
        stocks.push(parts[0]);
      }
    });//each stock
    eval(callback+'(stocks)');
  });
}//coop_stock_orderGetOrder


function coop_stock_orderMakeLinks(stocks) {
  let html = '';
  for (i=0; i<stocks.length; i++) {
    html += stocks[i] + ' ';
    html += '<a href="#!" onclick="coop_stock_orderSelect(\''+stocks[i]+'\')">select<\a> ';
    html += '<a href="#!" onclick="coop_stock_orderViewStock(\'' + stocks[i] + '\')">view</a>';
    //html += '<input type="button" value="select" onclick="coop_stock_orderSelect(\''+stocks[i]+'\')"> ';
    html += '<br>';
  }
  
  return html;
}//coop_stock_orderMakeLinks


function coop_stock_orderRemoveStock() {
  let descriptive_name = coop_stock_orderGetDescriptiveName();
  let data = {"action": "remove-stock", "stock_name": descriptive_name};
  $.ajax({
    type: "POST",
    url: '/ordering/coop_order',
    data: data,
    cache: true,
  })
  .fail(function(data) {
    alert("Unable to remove stock '"+descriptive_name+"' due to " + data);
  })
  .done(function(data) {
    coop_stock_orderGetOrder(true, 'coop_stock_orderUpdateStockList') 
    //coop_stock_orderUpdateStockList();
  });
}//coop_stock_orderRemoveStock


function coop_stock_orderSelect(stock_name) { 
  $('#coop_stock_order-stock_name').val(stock_name);
  $('.coop_stock_popup').hide();
/*
  $('#coop_stock_order-none').text(heading);
  coop_stock_orderAddToList(stock_name, '');
  $('#coop_stock_order-stock_name').val('');
  $('#coop_stock_order-stock_comment').val('');
  $('.coop_stock_popup').hide();
*/
}//coop_stock_orderSelect


function coop_stock_orderStockComment() {
  let descriptive_name = coop_stock_orderGetDescriptiveName();
  let data = {"action": "get-comment", "stock_descriptive_name": descriptive_name};
  $.ajax({
    type: "POST",
    url: '/ordering/coop_order',
    data: data,
    cache: true,
  })
  .fail(function(data) {
    alert("Unable get comment for stock '"+stock_name+"' due to " + data);
  })
  .done(function(data) {
    $('#coop_stock_order-stock_name').val(descriptive_name);
    $('#coop_stock_order-stock_comment').val(data);
    $('#coop_stock_order-edit_btn').val('Update');
  });
}//coop_stock_orderStockComment


function coop_stock_orderStockSelected() {
  let enable = $('#coop_stock_order-select').children("option:selected").length > 0
  coop_stock_orderEnableStockButtons(enable);
}//coop_stock_orderStockSelected


function coop_stock_orderSubmit() {
  coop_stock_orderGetOrder(true, 'coop_stock_orderSubmitOrder');
}//coop_stock_orderSubmit


function coop_stock_orderSubmitOrder(stocks) {
  let errors = coop_stock_orderVerifyForm();
  if (errors.length > 0) {
    let html = '<b style="color:crimson">Unable to submit request.</b><br>';
    html += '&bull;' + errors.join('<br>&bull;');
    $('#coop_stock_popup_content').html(html); 
    $('.coop_stock_popup').show();
  }
  else {
    let count = $('#coop_stock_order-select option').length;
    let msg = "Are you sure you are ready to submit this request?\n\n";
    msg += "Please verify your request for " + count + " stocks. ";
    msg += "Note that long lists may be truncated in this box, ";
    msg += "indicated by '...', in which case, ";
    msg += "verify that the number of stocks is correct.\n\n";
    msg += stocks.join("\n");
    msg += "\n";
    if (confirm(msg)) {
      let data = {
        "action": "submit", 
        "stock_order": stocks.join(':::'),  // Permits CRs in comments
        "name": $('#coop_stock_order-name').val(),
        "phone": $('#coop_stock_order-phone').val(),
        "address": $('#coop_stock_order-address').val(),
        "country": $('#coop_stock_order-country').val(),
        "email": $('#coop_stock_order-email').val(),
        "instructions": $('#coop_stock_order-instructions').val(),
        "genome": $('#coop_stock_order-genome').val(),
      };
      $.ajax({
        type: "POST",
        url: '/ordering/coop_order',
        data: data,
        cache: true,
      })
      .fail(function(data) {
        let msg = "Failed to submit order. ";
        msg += "Please contact the Maize Genetics Cooperation Stock Center ";
        msg += "directly at maize@uiuc.edu with your request.\n\n";
        msg += stock_list;
        alert(msg);
      })
      .done(function(data) {
       window.location = '/ordering/coop_order/completed';
      });
    }
  }
}//coop_stock_orderSubmit


function coop_stock_orderUpdateStockList(stocks) {
  $('#coop_stock_order-select').empty();
  if (stocks.length == 0) {
    $('#coop_stock_order-none').text('Your stock order is empty');
  }
  else {
    $('#coop_stock_order-none').text('Your order includes these stocks:');
    stocks.forEach(function(s) {
      $('#coop_stock_order-select').append(new Option(s, s));
    });
  }
}//coop_stock_orderUpdateStockList


function coop_stock_orderVerifyForm() {
  let errors = [];
  if ($('#coop_stock_order-select option').length == 0) {
    errors.push("You haven't listed any stocks in your request.");
  }
  if ($('#coop_stock_order-name').val() == '') {
    errors.push("You must provide a name.");
  }
  if (!$.trim($('#coop_stock_order-address').val())) {
    errors.push("No shipping address provided.");
  }
  if ($('#coop_stock_order-country').val() == '') {
    errors.push("Please indicate your country.");
  }
  if ($('#coop_stock_order-email').val() == '') {
    errors.push("Please provide your e-mail address.");
  }
  if (($('#coop_stock_order-country').val() != "United States of America"
           && $('#coop_stock_order-country').val() != "USA"
           && $('#coop_stock_order-country').val() != "US"
           && $('#coop_stock_order-country').val() != "usa")) {
    let pn = $('#coop_stock_order-phone').val();
    num = pn.replace(/[^\d]/g, '');
    if (!num || num.length < 10) {
      errors.push("Please provide a valid phone number when shipping to countries outside the USA.");
    }
  }
  
  return errors;
}//coop_stock_orderVerifyForm


function coop_stock_orderViewStock(stock_name=null) {
  if (stock_name) {
    let parts = stock_name.split(' ');
    stock_name = parts[0];
  }
  else {
    let selected = $('#coop_stock_order-select').find(":selected");
    if (selected.length > 0) {
      let parts = $('#coop_stock_order-select').val().split(' ');
      stock_name = parts[0];
    }
  }
  
  window.open('/data_center/stock/' + stock_name, '_blank');;
}//coop_stock_orderViewStock


