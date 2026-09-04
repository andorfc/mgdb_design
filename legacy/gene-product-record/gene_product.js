 /**
  * Refreshes display of the reference data
  */       
  function toggle_references(display)
  {   
    if(display == "show")
    {
      document.getElementById("show_ref").style.display = "block";
      document.getElementById("hide_ref").style.display = "none";
    }
    else
    {
      document.getElementById("show_ref").style.display = "none";
      document.getElementById("hide_ref").style.display = "block";
    }
  }
  
  function textbox_change(id, box)
  {
    if(document.getElementById(id).value != "")
      document.getElementById(box).checked = true;
    else
      document.getElementById(box).checked = false;
  }
  
  function updateBox1()
  {

    if(document.getElementById('geneProductName').value != "")
       document.getElementById('box1').checked = true;
    else
       document.getElementById('box1').checked = false;
   }


  function updateBox3() 
  {

    if(document.getElementById('ec1').value != "" &&
       document.getElementById('ec2').value != "" &&
       document.getElementById('ec3').value != "" &&
       document.getElementById('ec4').value != "" )
          document.getElementById('box3').checked = true;
    else
       document.getElementById('box3').checked = false;
  }


  function updateBox4()
  {
    if(document.getElementById('locusName').value != "")
       document.getElementById('box4').checked = true;
    else
       document.getElementById('box4').checked = false;
  }


  function updateSelectBox(selName, box)
  {
    if(document.getElementById(selName).selectedIndex == 0)
       document.getElementById(box).checked = false;
    else
       document.getElementById(box).checked = true;
  }
  
  /**
  * Performs an advanced search
  */
  function gp_adv_search(div_name, pagenum)
  {  
    try {
        var arrSearchOpt = {};
        //Add all checkbox params to array
        arrSearchOpt["use1"] = document.getElementById("use1").checked;
        arrSearchOpt["use2"] = document.getElementById("use2").checked;
        arrSearchOpt["use3"] = document.getElementById("use3").checked;
        arrSearchOpt["use4"] = document.getElementById("use4").checked;
        arrSearchOpt["use5"] = document.getElementById("use5").checked;
        arrSearchOpt["use6"] = document.getElementById("use6").checked;
        arrSearchOpt["use7"] = document.getElementById("use7").checked;
        arrSearchOpt["use8"] = document.getElementById("use8").checked;
        arrSearchOpt["use9"] = document.getElementById("use9").checked;
        
        //Add all select values to array
        arrSearchOpt["geneProductName"] = document.getElementById("geneProductName").value;
        arrSearchOpt["typeID"] = document.getElementById("typeID").value;
        
        arrSearchOpt["ec1"] = document.getElementById("ec1").value;
        arrSearchOpt["ec2"] = document.getElementById("ec2").value;
        arrSearchOpt["ec3"] = document.getElementById("ec3").value;
        arrSearchOpt["ec4"] = document.getElementById("ec4").value;
        
        arrSearchOpt["locusName"] = document.getElementById("locusName").value;
        arrSearchOpt["conditionID"] = document.getElementById("conditionID").value;
        arrSearchOpt["localizationID"] = document.getElementById("localizationID").value;
        arrSearchOpt["metaPathID"] = document.getElementById("metaPathID").value;
        arrSearchOpt["metaConstitID"] = document.getElementById("metaConstitID").value;
        arrSearchOpt["motifDescription"] = document.getElementById("motifDescription").value;
       
        if(document.getElementById("adv_limit").checked) {
          arrSearchOpt["adv_limit_val"] = document.getElementById("adv_limit_val").value;
        }
        else {
          arrSearchOpt["adv_limit_val"] = 0;
        }
        getAdvSearch("gene_product", div_name, arrSearchOpt, pagenum);    
    }
    catch (e) { //This search is being run from shadowbox, so re-use old parameters.
        getAdvSearch("gene_product", div_name, false, pagenum); 
    }
  }
  