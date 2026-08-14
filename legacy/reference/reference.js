  
  function textbox_change(id, box)
  {
    if(document.getElementById(id).value != "")
      document.getElementById(box).checked = true;
    else
      document.getElementById(box).checked = false;
  }
  
  /**
  * Performs an advanced search
  */
  function ref_adv_search(div_name, pagenum) {  
    try {
        var arrSearchOpt = {};
        //Add all checkbox params to array
        if(document.getElementById("author1").value == "") 
          document.getElementById("box_author1").checked = false;
        if(document.getElementById("author2").value == "")
          document.getElementById("box_author2").checked = false;
        if(document.getElementById("pub_year").value == "")
          document.getElementById("box_pub_year").checked = false;
        if(document.getElementById("title").value == "")
          document.getElementById("box_title").checked = false;
          
        arrSearchOpt["box_author1"] = document.getElementById("box_author1").checked;
        arrSearchOpt["box_author2"] = document.getElementById("box_author2").checked;
        arrSearchOpt["box_journal"] = document.getElementById("box_journal").checked;
        arrSearchOpt["box_pub_year"] = document.getElementById("box_pub_year").checked;
        arrSearchOpt["box_title"] = document.getElementById("box_title").checked;
        arrSearchOpt["box_ed_board"] = document.getElementById("box_ed_board").checked;
        arrSearchOpt["box_pubtypes"] = document.getElementById("box_pubtypes").checked;
        
        //Add all select values to array
        arrSearchOpt["author1"] = document.getElementById("author1").value; //references by this author
        arrSearchOpt["author2"] = document.getElementById("author2").value; //references by this author (second)
        arrSearchOpt["journal"] = document.getElementById("journal").value; //references from this journal
        arrSearchOpt["pub_year"] = document.getElementById("pub_year").value; //references published in this year
        arrSearchOpt["title"] = document.getElementById("title").value; //references with title containing
        arrSearchOpt["pubtypes"] = document.getElementById("pubtypes").value;
        if(document.getElementById("adv_limit").checked) {
          arrSearchOpt["adv_limit_val"] = document.getElementById("adv_limit_val").value;
        }
        else {
          arrSearchOpt["adv_limit_val"] = 0;
        }
        getAdvSearch("reference", div_name, arrSearchOpt, pagenum);
    }
    catch (e) { //This search is being run from shadowbox, so re-use old parameters.
        getAdvSearch("reference", div_name, false, pagenum); 
    }
  }