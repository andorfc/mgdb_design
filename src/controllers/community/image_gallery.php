<?php
/* file: image_gallery.php
 * 
 * purpose: build an image gallery page.
 *
 * history:
 *  04/13/20  eksc  created
 */
 
  include_once('./lib/Bauplan.php');
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  $DBConn = connect_to_database();
  if (!$DBConn) {
    echo "Unable to connect to database.";
    exit;
  }
  
  $gallery = ID;
  
  $bauplan->title("MaizeGDB Image Gallery");  
  $bauplan->includeCss('/css/image_gallery.css');
  $bauplan->includeScript('https://ajax.googleapis.com/ajax/libs/jquery/3.5.0/jquery.min.js');
  $bauplan->includeScript('/js/image_gallery.js');
  
  $tmpl = $mgdb->get('body')->load('templates/community/image_gallery.bau');

  if (!$gallery) {
    $tmpl->get('no-gallery')->unmute();
  }
  else {
    $tmpl->get('gallery-name')->replace(getImageGalleryName($gallery));

    // Move this to config file!
    $image_dir = 'images/galleries/' . ID;
    $image_url = '/images/galleries/' . ID;
  
    $images = getImages($gallery, $image_dir, $DBConn);
    buildSlides($tmpl, $gallery, $image_url, $images);
    
    $tmpl->get('gallery')->unmute();
  }


//////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////

function getImages($gallery, $image_dir, $DBConn) {
  $images = array();
  
  $files = scandir("$image_dir");
  
  // Eventually will want to look these up to get captions, credits, et cetera.
  foreach ($files as $file) {
    $caption = '';
    $credit  = '';
    $full_filename = "$image_dir/$file";
    if (is_file($full_filename) && 
          (exif_imagetype($full_filename)
            || mime_content_type($full_filename) == 'application/pdf')) {
      // Is an image file
      $sql = "
        SELECT * FROM mgdb.image_gallery 
        WHERE gallery_name='$gallery' AND image_name='$file'";
      $sth = make_query($DBConn, $sql);
      if ($row = retrieve_row($sth)) {
        if (!$row['hide'] 
               || strtolower($row['hide']) == 'n' 
               || strtolower($row['hide']) == 'f') {
          array_push($images, array(
                     'gallery_image'     => $file,
                      'gallery_image_tn' => $row['thumbnail_name'],
                      'caption'          => mgdb_safe_html($row['caption']),
                      'credit'           => $row['credit'],
          ));
        }//not hidden
      }//image record found
      else {
        // no image record, assume okay
        $tn_file = (strstr('pdf', $file)) 
                   ? preg_replace("/(.*)\.(.*)/", '$1_tn.jpg', $file)
                   : preg_replace("/(.*)\.(.*)/", '$1_tn.$2', $file);
        array_push($images, array(
                   'gallery_image'    => $file,
                   'gallery_image_tn' => $tn_file,
                   'caption'          => '',
                   'credit'           => '',
        ));
      }//no image record
    }//file is an image
  }//each file
  
  return $images;
}//getImages


function buildSlides($tmpl, $gallery, $image_url, $images) {
  $slide_count = count($images);
  $slide_num   = 0;
  $tn_html     = '';
  $slide_html  = '';
  foreach ($images as $img) {
    $slide_num++;
        
    // Thumbnail 
    $bauplan = new Bauplan('');
    $sub_tmpl = $bauplan->template()->load('templates/community/image_gallery-thumbnail.bau');
    $sub_tmpl->get('gallery-image-tn')->replace("$image_url/tns/" . $img['gallery_image_tn']);
    $sub_tmpl->get('slide-num')->replace($slide_num);
    $tn_html .= $sub_tmpl->getHTML();
    
    // Corresponding slide
    $sub_tmpl = $bauplan->template()->load('templates/community/image_gallery-slide.bau');
    $sub_tmpl->get('gallery-name')->replace($gallery);
    $sub_tmpl->get('slide-num')->replace($slide_num);
    $sub_tmpl->get('slide-count')->replace($slide_count);
    
    // Image or PDF
    $sub_tmpl->get('gallery-image-url')->replace("$image_url/" . $img['gallery_image']);
    if (strstr($img['gallery_image'], 'pdf')) {
      $sub_tmpl->get('pdf-file')->unmute();
    }
    else {
      $sub_tmpl->get('image-file')->unmute();
    }
    
    if ($img['caption']) {
      $sub_tmpl->get('slide-caption')->replace(mgdb_safe_html($img['caption']));
    }
    
    // Image file name to permit removal
    $sub_tmpl->get('gallery-image-name')->replace($img['gallery_image']);
    
    $slide_html .= $sub_tmpl->getHTML();
  }//each event
  
  $tmpl->get('thumbnails')->replace($tn_html);
  $tmpl->get('slides')->replace($slide_html);
}//buildSlides


function getImageGalleryName($gallery) {
  // Kind of a clunky way to deal with this, but more straightforward than 
  //   putting the name in the database.
  switch ($gallery) {
    case 'MM2010':
      return "Maize Genetics Conference, 2010 - Italy";
    case 'IMS1975':
      return "1975 International Maize Symposium - Urbana, IL";
    default:
      return "Image gallery";
  }//switch
}//getImageGalleryName

?>
