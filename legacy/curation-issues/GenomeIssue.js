// file: GenomeIssue.js
//
// purpose: JavaScript support for reporting genome issues
//
// history:
//  08/27/12  Steven Perez  created
//  10/01/12  eksc          modified for website
//  08/27/13  eksc          reduced required fields

function validateIssueForm() {
  var valid = true;
  var name_given         = document.forms["problem"]["sub_name"].value;
  var email              = document.forms["problem"]["email"].value;
  var affiliation        = document.forms["problem"]["affiliation"].value;
  var organism           = document.forms["problem"]["organism"].value;
  var position           = document.forms["problem"]["position"].value;
  var gene_model         = document.forms["problem"]["gene_model"].value
  var gene_model_version = document.forms["problem"]["gene_model_version"].value;
  var chromosome         = document.forms["problem"]["chromosome"].value;
  var description        = document.forms["problem"]["description"].value;
//  var sequence    = document.forms["problem"]["sequence"].value;
  
  var coords_given;
  if ((document.forms["problem"]["chr_start"].value == '' 
       || document.forms["problem"]["chr_end"].value == '')
      && (document.forms["problem"]["first_acc"].value == ''
          || document.forms["problem"]["last_acc"].value == '')
      ) {
    coords_given = false;
  }
  else {
    coords_given = true;
  }

  if (name_given == null || name_given == "") {
    document.getElementById("sub_name").style.background = "yellow";
    valid = false;
  }
  if (email == null || email == "") {
    document.getElementById("email").style.background="yellow";
    valid = false;
  }
  if (affiliation == null || affiliation == "") {
    document.getElementById("affiliation").style.background="yellow";
    valid = false;
  }
  if (position == 0) {
    document.getElementById("position").style.background="yellow";
    valid = false;
  }
  if (description == null || description == "") {
    document.getElementById("description").style.background="yellow";
    valid = false;
  }
//  if (sequence == null || sequence == "") {
//    document.getElementById("sequence").style.background="yellow";
//    valid = false;
//  }
  if (organism == 0) {
    document.getElementById("organism").style.background="yellow";
    valid = false;
  }
//  if (chromosome == 0) {
//    document.getElementById("chromosome").style.background="yellow";
//    valid = false;
//  }
//  if (!coords_given) {
//    var t = document.getElementById("coord_info");
//    document.getElementById("coord_info").style.background="yellow";
//    valid = false;
//  }
  if (chromosome != 0 && !coords_given) {
    document.getElementById("coord_info").style.background="yellow";
    valid = false;
  }
  if (chromosome == 0 && coords_given) {
    document.getElementById("chromosome").style.background="yellow";
    valid = false;
  }

// Allow NONE (not on a chromosome)  
//  if (chromosome == 0 && !coords_given && gene_model == '') {
//    alert("You must give information about the gene model or genome location for this issue.");
//    return false;
//  }
  
  if (!valid) {
    alert("Required fields must be filled out");
    return false;
  }
}//validateForm()


function Check() {
  var pos_els = document.getElementById("chr_pos_els");
  var acc_els = document.getElementById("acc_list_els");

  if (document.getElementById("range_acc").checked)  {
    document.getElementById("chr_start").disabled = true;
    document.getElementById("chr_end").disabled   = true;
    pos_els.className = "disabled";
    document.getElementById("first_acc").disabled = false;
    document.getElementById("last_acc").disabled  = false;
    acc_els.className = "";
    document.getElementById("first_acc").focus();
  }
  else {
    document.getElementById("first_acc").disabled = true;
    document.getElementById("last_acc").disabled  = true;
    acc_els.className = "disabled";
    document.getElementById("chr_start").disabled = false;
    document.getElementById("chr_end").disabled   = false;
    pos_els.className = "";
    document.getElementById("chr_start").focus();
  }
}//Check()


function resetField(var1) {
  document.getElementById(var1).style.background="white";
}//resetField()
