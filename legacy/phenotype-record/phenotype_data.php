<?PHP
/* file: phenotype_data.php
 *
 * purpose: display the various sections of a phenotype record; called via Ajax
 *
 * history:
 *  06/22/12  jportwood  created from old website code (cgi-bin/displayphenorecord.cgi)
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);
  
  if (!$id) {
    reportError("No id given to phenotype_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to phenotype_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/phenotype_sections.bau');
  
  $DBConn = connect_to_database();

  // If annotator, check for super curator
  if ($username) {
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    $author_id = $user_info['annotation_author_id'];
  }
  
  // Clean up input typed by user
  $id = validate_input($DBConn, $id);
  
  $query_record = "SELECT * FROM phenotype WHERE id = '$id'";
  $stmt_record = make_query($DBConn, $query_record);
  $arrRecord = retrieve_row($stmt_record);

  switch ($type) {
    case 'top':
      show_top($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'overview':
      show_overview($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'annotations':
      showAnnotations($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'variations':
      show_variations($tmpl, $id, $DBConn);
      break;
    case 'stocks':
      show_stocks($tmpl, $id, $DBConn);
      break;
  }
  $bauplan->publish();
  
  
function show_top($tmpl, $id, $arrRecord, $DBConn) { 
  $show_ref = getCGIParam("show_ref", 'G', false);
  $print = getCGIParam("print", 'G', false);
  
  $tmpl->get('name')->replace($arrRecord['name']);
  $tmpl->get('escaped_name')->replace(addSlashes($arrRecord['name']));
  
  if ($syn = read_synonyms($DBConn, $id)) {
    $tmpl->get('synonyms')->replace($syn);
    $tmpl->get('syn')->unmute();
  }
  
  $tmpl->get('top')->unmute();
  show_references($id, $DBConn, $tmpl, $print, $show_ref);
}//showTop
  
  
function show_overview($tmpl, $id, $arrRecord, $DBConn) {
  global $system;
  $tmpl->get('img_url')->replace($system['image_server_url']);

  $definition = read_pheno_definition($DBConn, $id);
  if (strlen($definition) > 0) {
    $tmpl->get('definition')->replace($definition);
    $tmpl->get('definition-part')->unmute();  
  }
  
  $comments = getComments($DBConn, $id);
  if ($comments != '') {
    $tmpl->get('comments')->replace($comments);
    $tmpl->get('comment-part')->unmute();  
  }
  
  if (isset($arrRecord['inheritance'])) {
    $inheritance = read_inheritance($DBConn, $arrRecord);
    $tmpl->get('inherit')->replace($inheritance);
    $tmpl->get('inheritance')->unmute();
  }
  
  if (isset($arrRecord['intensity'])) {
    $intensity = read_intensity($DBConn, $arrRecord);
    $tmpl->get('intense')->replace($intensity);
    $tmpl->get('intensity')->unmute();
  }
  
  if (isset($arrRecord['trait'])) {
    $trait = read_trait($DBConn, $arrRecord);
    $tmpl->get('phen_trait')->replace($trait);
    $tmpl->get('trait')->unmute();
  }
  
  if (isset($arrRecord['value'])) {
    $value = read_value($DBConn, $arrRecord);
    $tmpl->get('phen_value')->replace($value);
    $tmpl->get('value')->unmute();
  }
  
  $body_parts = read_body_part($DBConn, $id);
  if (strlen($body_parts) > 0) {
    $tmpl->get('body_part')->replace($body_parts);
    $tmpl->get('body_parts')->unmute();  
  }
  
  $dev_stages = read_dev_stage($DBConn, $id);
  if (strlen($dev_stages)> 0) {
    $tmpl->get('dev_stage')->replace($dev_stages);
    $tmpl->get('dev_stages')->unmute();  
  }
  
  $meta_paths = read_meta_path($DBConn, $id);
  if (strlen($meta_paths) > 0) {
    $tmpl->get('meta_path')->replace($meta_paths);
    $tmpl->get('meta_paths')->unmute();  
  } 
  
  $genes = read_genes($DBConn, $id);
  if (strlen($genes) > 0) {
    $tmpl->get('gene')->replace($genes);
    $tmpl->get('genes')->unmute();  
  } 
  
  $offsite_resources = read_offsite_resources($DBConn, $id);
  if ($offsite_resources) {
    $tmpl->get('offsite_sec')->loop($offsite_resources);
    $tmpl->get('offsite')->unmute();
  }
  
  if (!show_images($tmpl, $id, $DBConn) && $definition == '' && !$comments 
        && !isset($arrRecord['inheritance']) && !isset($arrRecord['intensity']) 
        && !isset($arrRecord['trait']) && !isset($body_parts) && !isset($dev_stages) 
        && !isset($meta_paths) && !isset($genes) && !$offsite_resources) {
   $tmpl->get('no_overview')->unmute();        
  }
  
  $tmpl->get('overview')->unmute();
}//showOverview


function showAnnotations($tmpl, $id, $arrRecord, $DBConn) {
  global $username, $super_curator, $author_id;
  
  /////// Look for user annotations ///////
  $arrAnnotations = getAnnotations($DBConn, $id, '', $username, $author_id, 
                                   $super_curator, 'id');
  if (!$arrAnnotations || count($arrAnnotations) == 0) {
    $tmpl->get('no-user')->unmute();
  }
  else if ($super_curator) {
    $tmpl->get('annotation-user-list-ex')->loop($arrAnnotations);
    $tmpl->get('annotation-user-curator')->unmute();
  }
  else {
    $tmpl->get('annotation-user-list')->loop($arrAnnotations);
    $tmpl->get('annotation-user')->unmute();
  }

/* broken, and no one used it when it worked
   // Always show curation section; will prompt for log-in if need be
   $tmpl->get('curation')->unmute();
*/

  $tmpl->get('id')->replace($id);
  $tmpl->get('rec_name')->replace($arrRecord['name']);

   $tmpl->get('annotations')->unmute();
}//showAnnotations


function show_variations($tmpl, $id, $DBConn) {
  global $system;
  $tmpl->get('img_url')->replace($system['image_server_url']);

  show_img_table_var($tmpl, $id, $DBConn);
  $tmpl->get("id")->replace($id);

  $query_variations = "
    SELECT b.name, b.variationof, b.id
    FROM var_pheno_effects a, variation b, id_num c
    WHERE a.id=b.id AND b.id=c.id AND c.curation_lvl = 0 AND a.pheno_effect=$id
    ORDER BY LOWER(b.name)";
  $stmt_variations = make_query($DBConn, $query_variations);
  $variation_results = array();
  $var_count = 0;
  while ($arrVariations = retrieve_row($stmt_variations)) {
    $variation_results[$var_count]["var_id"] = $arrVariations['id'];
    $variation_results[$var_count]["var_name"] = $arrVariations['name'];
    if (isset($arrVariations['variationof'])) {
      $query_var_locus = "
        SELECT a.id, a.name, a.full_name
        FROM locus a, id_num b
        WHERE a.id=b.id AND b.curation_lvl=0 AND a.id=" . $arrVariations['variationof'];
      $stmt_var_locus = make_query($DBConn, $query_var_locus);
      $arrVarLoci = retrieve_row($stmt_var_locus);
      if (isset($arrVarLoci['name'])) {
        $locus = " (variation of <a href=\"locus?id=" . $arrVarLoci['id'] . "\">" . trim($arrVarLoci['name']);
        if (isset($arrVarLoci['full_name'])) {
          $locus = $locus . " <i>" . trim($arrVarLoci['full_name']) . "</i></a>";
        }
        $locus = $locus . ")";
        $variation_results[$var_count]['locus'] = $locus;
      }
    }
    
    $var_count = $var_count + 1;
  }//each row
  
  if (!$variation_results || count($variation_results) == 0) {
    $tmpl->get("no_var")->unmute();
  }
  
  $tmpl->get("var_results")->loop($variation_results);
  $tmpl->get("variations")->unmute();
}//show_variations


function show_stocks($tmpl, $id, $DBConn) {
  $query_stocks = "
    SELECT s.name, s.id, p.name as available_from, d.description
    FROM stock_phenotypes sp
      INNER JOIN phenotype ph ON ph.id=sp.phenotype
      INNER JOIN stock s ON s.id=sp.id
      INNER JOIN id_num i ON i.id=sp.id
      LEFT OUTER JOIN person p ON p.id=s.available_from
      LEFT OUTER JOIN description d ON d.id=s.id
    WHERE i.curation_lvl=0 AND ph.id=$id 
    ORDER BY LOWER(s.name)";
  $stmt_stocks = make_query($DBConn, $query_stocks);
  $stock_results = array();
  $stock_count = 0;
  $num = 1;
  while ($arrStocks = retrieve_row($stmt_stocks)) {
    //add bold tags if stock is available from the stock center
    if ($arrStocks['available_from'] == 'Maize Genetics Cooperation - Stock Center') {
      $stock_results[$stock_count]["b_stock_id$num"] = $arrStocks['id']; 
      $stock_results[$stock_count]["b_stock_name$num"] = trim($arrStocks['name']);
      $stock_results[$stock_count]["b_stock_desc$num"] = trim($arrStocks['description']);
    }
    else {
      $stock_results[$stock_count]["stock_id$num"] = $arrStocks['id']; 
      $stock_results[$stock_count]["stock_name$num"] = trim($arrStocks['name']);
      $stock_results[$stock_count]["stock_desc$num"] = trim($arrStocks['description']);
    }
    
    // create columns
    if ($num > 1 && $num % 3 == 0) {
      $num = 1;
      $stock_count++;
    }
    else {
      $num++;
    }
  }
  
  if (!$stock_results || count($stock_results) == 0) 
     $tmpl->get("no_stocks")->toggle();   
  else
     $tmpl->get("stock_results")->loop($stock_results);
     
  $tmpl->get("stocks")->unmute();
}//show_stocks
  
   
function show_references($id, $DBConn, $tmpl) {
  $query_related_articles = "
    SELECT a.contents, a.reference 
    FROM id_reference a, id_num b 
    WHERE a.reference=b.id AND b.curation_lvl=0 AND a.id=$id 
    ORDER BY a.contents";
  $stmt_related_articles = make_query($DBConn, $query_related_articles);

  $reference = array();
  $count = 0;
  while ($arrRelatedArticles = retrieve_row($stmt_related_articles)) {
    if (isset($arrRelatedArticles['contents'])) {
      $query_contents = "
        SELECT name FROM term WHERE id = " . $arrRelatedArticles['contents'];
      $stmt_contents = make_query($DBConn, $query_contents);
      $arrContents = retrieve_row($stmt_contents);
      $reference[$count]["cont_name"] = $arrContents['name'];
    
      if (isset($arrContents['name'])) {
        $arrContents['name'] = "general";
      }
    }
    else {
      $reference[$count]["cont_name"] = "general";
    }
    
    if (strlen($arrRelatedArticles['reference']) > 0) {
      $query_reference = "
        SELECT id, name, title FROM reference 
        WHERE id = " . $arrRelatedArticles['reference'];
      $stmt_reference = make_query($DBConn, $query_reference);
      $arrReference = retrieve_row($stmt_reference);
      $reference[$count]["ref_id"] = $arrReference['id'];
      $reference[$count]["ref_title"] = addslashes($arrReference['title']);
      $reference[$count]["ref_name"] = trim($arrReference['name']);
    }
    $count++;
  }//each row
  
  if (count($reference) > 0) {
    $tmpl->get("fill_ref")->loop($reference);
  }

  $matching_article_count = $count;

  $bool = settype($matching_article_count, "integer");
  if ($matching_article_count > 0) {
    $tmpl->get('display')->replace('block');
  }
  else {
    $tmpl->get('display')->replace('none');
  }
  
  $tmpl->get("match_count")->replace($matching_article_count);
  $tmpl->get("references")->unmute();
}//show_references

  
function show_images($template, $id, $DBConn) {
  $query_images = "
    SELECT DISTINCT ON(url, caption) url, caption FROM web_image
    WHERE id = " . $id;
  $stmt_images = make_query($DBConn, $query_images);
  $arrImages = get_all_rows($stmt_images);
  
  $num_images = ($arrImages) ? count($arrImages) : 0;
  $img_count = 0;
  $bgcolor = "#F5F5F5";
  $img_results = array();
  
  while ($num_images > 0 && strlen($arrImages[$img_count]['caption']) > 0) {
    if ($img_count % 2 == 0)
      $img_results[$img_count]['bgcolor'] = "#F5F5F5";
    else
      $img_results[$img_count]['bgcolor'] = "";
        
     
    $img_results[$img_count]['img_count'] = $img_count + 1;
    $img_results[$img_count]['caption'] = $arrImages[$img_count]['caption'];
    $img_results[$img_count]['url'] = $arrImages[$img_count]['url'];

    $img_count++;
    if ($img_count == $num_images)
      break;
  }
  
  if ($num_images > 0) {
    $template->get('pheno_img_tbl')->loop($img_results);
    $template->get('id')->replace($id);
    $template->get('img_carousel')->unmute();
  }
  else {
    return false;
  }
}//show_images
  
/****************************************************
 ********************HELPER METHODS******************
 ***************************************************/
 
/**
 * Search for a definition for a specific ID and return as a string
 *
 */
function read_pheno_definition($DBConn, $id) {
  $query = "
    SELECT DISTINCT(memo) 
    FROM memo 
      INNER JOIN term ON term.id=memo.type_term
    WHERE memo.id = $id AND term.name = 'Definition'";
  $stmt = make_query($DBConn, $query);

  $definition = '';
  while ($row = retrieve_row($stmt)) {
    $definition .= "<br>&nbsp;&nbsp;" . $row['memo'];
  }
  
  return $definition;
}//read_pheno_definition
  
/**
 * Search for any comment(s) for a specific ID and return them as a string
 *
 */
/* use generic getComments instead
function read_pheno_comment($DBConn,$id) {
  $query = "
    SELECT DISTINCT(memo) 
    FROM memo 
      INNER JOIN term ON term.id=memo.type_term
    WHERE memo.id = $id AND term.name != 'Definition'";
  $stmt = make_query($DBConn, $query);
  $comments = get_all_rows($stmt);
  $comments = ($comments && count($comments) > 0) ? $comments : false;
  
  return $comments;
}//read_pheno_comment
*/
  
  
/**
 * Grab the inheritance data for the record and return it
 */
function read_inheritance($DBConn, $arrRecord) {
  $query_inheritance = "
    SELECT name, term_comments FROM term 
    WHERE id = " . $arrRecord['inheritance'];
  $stmt_inheritance = make_query($DBConn, $query_inheritance);
  $inheritance_str = '';
  if ($arrInheritance = retrieve_row($stmt_inheritance)) {
    if (isset($arrInheritance['term_comments'])) {
      $inheritance_str = "
        <acronym title=\"" . trim($arrInheritance['term_comments']) . "\">" 
          . trim($arrInheritance['name']) . 
        "</acronym>";
    }
    else {
      $inheritance_str = $arrInheritance['name'];
    }
  }
  
  return $inheritance_str;
}//read_inheritance

  
/**
 * Grab the intensity data for the record and return it
 */
function read_intensity($DBConn, $arrRecord) {
  $query_intensity = "
    SELECT tm.name, tm.term_comments 
    FROM term tm, id_num idn
    WHERE tm.id = " . $arrRecord['intensity'] . "
      AND tm.id = idn.id
      AND idn.curation_lvl = 0";
  $stmt_intensity = make_query($DBConn, $query_intensity);
  $arrIntensity = retrieve_row($stmt_intensity);
  $intensity_str = "";
  if (isset($arrIntensity['term_comments'])) {
    $intensity_str = "<acronym title=\"" . trim($arrIntensity['term_comments']) . "\">" . trim($arrIntensity['name']);
  }
  else {
    $intensity_str = $arrIntensity['name'];
  }
   
   return $intensity_str;
}//read_intensity
  
  
/**
 * Grab the trait data for the record and return it
 */
function read_trait($DBConn, $arrRecord) {
  $trait_str = '';
  
  if (isset($arrRecord['trait'])) {
    $query_trait = "
      SELECT name FROM term t
        INNER JOIN id_num ON id_num.id=t.id
      WHERE id_num.curation_lvl = 0 AND t.id = " . $arrRecord['trait'];
    $stmt_trait = make_query($DBConn, $query_trait);
    $arrTrait = retrieve_row($stmt_trait);

    $trait_str = '';
    if (isset($arrTrait['name'])) {
      $trait_str = "<a href=\"trait?id=" . $arrRecord['trait'] . "\">" . $arrTrait['name'] . "</a>";
    }
  }
  
  return $trait_str;
}//read_trait

  
/**
 * Grab the value data for the record and return it
 */
function read_value($DBConn, $arrRecord) {
  $query_value = "
    SELECT tm.name, tm.term_comments 
    FROM term tm, id_num idn
    WHERE tm.id = " . $arrRecord['value'] . "
      AND tm.id = idn.id
      AND idn.curation_lvl = 0";
   $stmt_value = make_query($DBConn, $query_value);
   $arrValue = retrieve_row($stmt_value);
   $value_str = '';
   if (isset($arrValue['term_comments'])) {
     $value_str = "<acronym title=\"" . trim($arrValue['term_comments']) . "\">" 
                 . trim($arrValue['name']) . "</acronym>";
   }
   else {
     $value_str = $arrValue['name'];
   }
   
   return $value_str;
}//read_value

  
/**
 * Grab the body part data for the record and return it
 */
function read_body_part($DBConn, $id) {
  $query_body_part = "
    SELECT a.name, a.term_comments 
    FROM term a, phenotype_body_parts b, id_num idn
    WHERE b.id=$id AND b.body_part=a.id AND a.id=idn.id AND idn.curation_lvl = 0
    ORDER BY a.name";
  $stmt_body_part = make_query($DBConn, $query_body_part);
  
  $body_part_count = 0;
  $body_part_str = '';
  while ($arrBodyPart = retrieve_row($stmt_body_part)) {
    if ($body_part_count != 0) {
      $body_part_str .= ', ';
    }
    
    $body_part_count = $body_part_count + 1;
    if (isset($arrBodyPart['term_comments'])) {
      $body_part_str .= "<acronym title=\"" . trim($arrBodyPart['term_comments']) 
                       . "\">" . trim($arrBodyPart['name']) . "</acronym>";
    }
    else {
     $body_part_str .= trim($arrBodyPart['name']);
    }
  }  
    
  return $body_part_str;
}//read_body_part

  
/**
 * Grab the development stage data for the record and return it
 */
function read_dev_stage($DBConn, $id) {
  $query_dev_stage = "
    SELECT a.name, a.term_comments 
    FROM term a, phenotype_dev_stages b, id_num idn
    WHERE b.id=$id AND b.dev_stage=a.id AND a.id=idn.id AND idn.curation_lvl=0
    ORDER BY a.name";
  $stmt_dev_stage = make_query($DBConn, $query_dev_stage);
  $dev_stage_count = 0;
  $dev_stage_str = '';
  
  while ($arrDevStage = retrieve_row($stmt_dev_stage)) {
    if ($dev_stage_count != 0) {
      $dev_stage_str .= ", ";
    }
    
    $dev_stage_count = $dev_stage_count + 1;
    if (isset($arrDevStage['term_comments'])) {
      $dev_stage_str .= "<acronym title=\"" . trim($arrDevStage['term_comments']) . "\">" 
                      . trim($arrDevStage['name']) . "</acronym>";
    }
    else {
     $dev_stage_str .= trim($arrDevStage['name']);
    }
  }//each row
  
  return $dev_stage_str;
}//read_dev_stage

  
/**
 * Grab the metabolic pathway data for the record and return it
 */
function read_meta_path($DBConn, $id) {
  $query_meta_path = "
    SELECT a.name, a.id 
    FROM meta_path a, phenotype_metabolic_pathway b, id_num c 
    WHERE b.id=$id AND b.metabolic_pathway=a.id AND a.id=c.id AND c.curation_lvl=0
    ORDER BY LOWER(a.name)";
  $stmt_meta_path = make_query($DBConn, $query_meta_path);
  $meta_path_count = 0;
  $meta_path_str = "";
  while ($arrMetaPath = retrieve_row($stmt_meta_path)) {
    if ($meta_path_count != 0) {
      $meta_path_str = $meta_path_str . ", ";
    }
    
    $meta_path_count = $meta_path_count + 1;
    $meta_path_str = $meta_path_str 
                   . "<a href=\"/data_center/mp/" 
                   . $arrMetaPath['id'] . "\">" 
                   . trim($arrMetaPath['name']) . "</a>";
  }//each row
  
  return $meta_path_str;
}//read_meta_path
  
  
 /**
 * Grab the genes data for the record and return it
 */
function read_genes($DBConn, $id) {
  $query_locus_function = "
    SELECT *
	FROM (
      SELECT DISTINCT l.id, l.name, l.full_name
      FROM phenotype p
        INNER JOIN var_pheno_effects pe ON pe.pheno_effect=p.id
        INNER JOIN variation v ON v.id=pe.id
        INNER JOIN locus l ON l.id=v.variationof
        LEFT OUTER JOIN locus_function lf ON lf.id=l.id
      WHERE p.id = $id
	) as sub1
	ORDER BY LOWER(name)";
  $stmt_locus_function = make_query($DBConn, $query_locus_function);
  $arrLocusFunction = retrieve_row($stmt_locus_function);
  $genes_str = '';
  
  if (isset($arrLocusFunction['id'])) {
    $genes_str = '<a href="/gene_center/gene/' . $arrLocusFunction['id'] . '">' 
                 . trim($arrLocusFunction['name']) . " <i>" . trim($arrLocusFunction['full_name']) 
                 . "</i></a>";
    $arrLocusFunction = retrieve_row($stmt_locus_function);
    while(isset($arrLocusFunction['id'])) {
      $genes_str = $genes_str . '<br><a href="/gene_center/gene/' . $arrLocusFunction['id'] . '">' 
                              . trim($arrLocusFunction['name']) . " <i>" 
                              . trim($arrLocusFunction['full_name']) . "</i></a>"; 
      $arrLocusFunction = retrieve_row($stmt_locus_function);
    }//each subsequent row
  }//have at least one row
  
  return $genes_str;
}//read_genes

  
function show_img_table_var($tmpl, $id, $DBConn) {
  //Grab image info and print them in table below the jquery carousel
  $query_images = "
    SELECT a.url, a.caption, c.id, c.name, d.name AS type 
    FROM web_image a, id_num b, variation c, term d 
    WHERE c.id IN (
      SELECT a.id 
      FROM variation a, id_num b, var_pheno_effects c 
      WHERE a.id=b.id AND b.curation_lvl=0 AND a.id=c.id AND c.pheno_effect=$id
    ) 
    AND c.id=a.id AND c.type=d.id AND c.id=b.id AND b.curation_lvl=0
    ORDER BY c.name, a.url";

  $stmt_images = make_query($DBConn, $query_images);
  $arrImages = get_all_rows($stmt_images);
  $img_count = 0;
  $num_images = ($arrImages) ? count($arrImages) : 0;
  $arrTblImages = "";
  $bgcolor = "#F5F5F5";
  $img_results = array();
  while ($num_images > 0 && strlen($arrImages[$img_count]['name']) > 0) {
    if ($img_count % 2 == 0) {
      $img_results[$img_count]['bgcolor'] = "#F5F5F5";
    } 
    else {
      $img_results[$img_count]['bgcolor'] = "";
    }
       
    $img_results[$img_count]['img_count'] = $img_count + 1;
    $img_results[$img_count]['caption'] = $arrImages[$img_count]['caption'];
    $img_results[$img_count]['name'] = $arrImages[$img_count]['name'];
    $img_results[$img_count]['type'] = $arrImages[$img_count]['type'];
    $img_results[$img_count]['url'] = $arrImages[$img_count]['url'];

    $img_count++;
    
    if ($img_count >= $num_images)
      break;
  }
  
  if ($img_count > 0) {
    $tmpl->get('img_count')->replace($img_count);
    $tmpl->get('pheno_img_tbl_var')->loop($img_results);
    $tmpl->get('img_carousel_var')->unmute();
  }
}//show_img_table_var

  
function read_offsite_resources($DBConn, $id) {
  $query_keys = "
    SELECT a.db_person, a.key FROM ext_db_key a, id_num b
    WHERE a.id=$id AND a.db_person=b.id AND b.curation_lvl=0";
  $stmt_keys = make_query($DBConn, $query_keys);
  $count = 0;
  $offsite_result = array();
  while ($arrKeys = retrieve_row($stmt_keys)) {
    $query_person = "SELECT name, id FROM person WHERE id = " . $arrKeys["db_person"];
    $stmt_person = make_query($DBConn, $query_person);
    $arrPerson = retrieve_row($stmt_person);
    $query_url_prefix = "
      SELECT url_prefix FROM person_url_prefix WHERE id = " . $arrKeys["db_person"];
    $stmt_url_prefix = make_query($DBConn, $query_url_prefix);
    $arrUrlPrefix = retrieve_row($stmt_url_prefix);
    $offsite_result[$count]["person_name"] = trim($arrPerson['name']);
    $offsite_result[$count]["db_person"] = $arrKeys['db_person'];
    $offsite_result[$count]["url_prefix"] = trim($arrUrlPrefix['url_prefix']);
    $offsite_result[$count]["key_urlencode"] = urlencode(trim($arrKeys['key']));
    $offsite_result[$count]["key_display"] = trim($arrKeys['key']);
    $count++;
  }
  
  if ($count == 0) {
    return false;
  }
  
  return $offsite_result; 
}//read_offsite_resources
?>
