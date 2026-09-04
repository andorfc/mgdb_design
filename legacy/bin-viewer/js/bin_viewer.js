  function toggle_tabs(val)
  {
    document.getElementById(val).style.display = "inline";
    if (val == "gb_v3")
    {
      document.getElementById("gb_v2").style.display = "none";
      document.getElementById("gb_v1").style.display = "none";
      document.getElementById("gb_bac").style.display = "none";
    }
    else if (val == "gb_v2")
    {
      document.getElementById("gb_v3").style.display = "none";
      document.getElementById("gb_v1").style.display = "none";
      document.getElementById("gb_bac").style.display = "none";
    }
    else if (val == "gb_v1")
    {
      document.getElementById("gb_v3").style.display = "none";
      document.getElementById("gb_v2").style.display = 'none';
      document.getElementById("gb_bac").style.display = 'none';
    }
    else
    {
      document.getElementById("gb_v3").style.display = "none";
      document.getElementById("gb_v2").style.display = 'none';
      document.getElementById("gb_v1").style.display = 'none';
    }
  }

  var current_tab = "chr1"; 
  function chr_tab(name) 
  {
    if (current_tab != name)
    {    
      $('#t'+name).removeClass('chr_tab_unfocus');
      $('#t'+name).addClass('chr_tab_focus');
      document.getElementById(name).style.display = 'inline';
    
      $('#t'+current_tab).removeClass('chr_tab_focus');
      $('#t'+current_tab).addClass('chr_tab_unfocus');
      document.getElementById(current_tab).style.display = 'none';
      current_tab = name;    
    }
  }
    

  