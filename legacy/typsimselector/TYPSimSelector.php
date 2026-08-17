<?PHP
  include_once('./lib/Bauplan.php');
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');
  
    $title= "TYPSimSelector";
 
    $binviewer = $mgdb->get('body')->load('templates/tools/TYPSimSelector.bau');
    
    $mgdb->get('body')->get('TYPSimSelector')->get('TYPSimSelector-content')->get('options_insert')->replace(get_taxa_options());
    $mgdb->get('body')->get('TYPSimSelector')->get('TYPSimSelector-content')->get('options_insert2')->replace(get_taxa2_options());
    $mgdb->get('body')->get('TYPSimSelector')->get('TYPSimSelector-content')->get('options_insert3')->replace(get_taxa_options2());
    $mgdb->get('body')->get('TYPSimSelector')->get('TYPSimSelector-content')->get('options_insert4')->replace(get_taxa2_options2());



    function query_taxa() {
        $system = getSystemInfo('mgdb.conf');
        $db = getSystemInfo('db.conf');
        $DBConn = connect_to_database(false); //jp - TODO: Errors are generated when using the Mondo Cache
        $taxa_query = "SELECT snp_entry_id, taxa FROM pidata.snp_entry ORDER BY taxa";
        $taxa_stmt = make_query($DBConn,$taxa_query,0);    
        return $taxa_stmt;
    }
    
    function query_taxa2() {
        $system = getSystemInfo('mgdb.conf');
        $db = getSystemInfo('db.conf');
        $DBConn = connect_to_database(false); //jp - TODO: Errors are generated when using the Mondo Cache
        $taxa_query2 = "SELECT DISTINCT iid1 FROM pidata.ames_merged ORDER BY iid1";
        $taxa_stmt2 = make_query($DBConn,$taxa_query2,0);    
        return $taxa_stmt2;
    }
    
    function get_taxa_options() {

        $taxa_stmt = query_taxa();


        $results_taxa = " ";
        $previous_taxa = "";
            while($arrtaxa = retrieve_row($taxa_stmt))
            {
                //Shows only the first instance of each unique taxa
                if ($arrtaxa["taxa"] != $previous_taxa){
                    $results_taxa.= "<option value='" . $arrtaxa["snp_entry_id"] . "'>" . $arrtaxa["taxa"] . "</option>" ;
                    $previous_taxa = $arrtaxa["taxa"];
                } 
            }  
        return $results_taxa;  
    }
  
    function get_taxa2_options() {

        $taxa_stmt2 = query_taxa();

        $results_taxa2 = "<option value='ALL'>ALL</option>";
        $previous_taxa = "";
            while($arrtaxa = retrieve_row($taxa_stmt2))
            {
                //Shows only the first instance of each unique taxa
                if ($arrtaxa["taxa"] != $previous_taxa){
                    $results_taxa2.= "<option value='" . $arrtaxa["snp_entry_id"] . "'>" . $arrtaxa["taxa"] . "</option>" ;
                    $previous_taxa = $arrtaxa["taxa"];
                } 
            }  
        return $results_taxa2;  
    }
    
    function get_taxa_options2() {

        $taxa_stmt = query_taxa2();

        $curator_results_taxa = " ";
        
            while($arrtaxa = retrieve_row($taxa_stmt))
            {
                $curator_results_taxa.= "<option value='" . $arrtaxa["iid1"] . "'>" . $arrtaxa["iid1"] . "</option>" ;

            }    
        return $curator_results_taxa;  
    }
    
    function get_taxa2_options2() {

        $taxa_stmt2 = query_taxa2();

        $curator_results_taxa2 = "<option value='ALL'>ALL</option>";

            while($arrtaxa = retrieve_row($taxa_stmt2))
            {
                $curator_results_taxa2.= "<option value='" . $arrtaxa["iid1"] . "'>" . $arrtaxa["iid1"] . "</option>" ;
            }   
        return $curator_results_taxa2;  
    }
  

 ?>
