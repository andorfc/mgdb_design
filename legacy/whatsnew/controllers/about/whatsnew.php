<?PHP
/* file: whatsnew.php
 *
 * purpose: display news
 *
 *          news text stored as XML in /data/
 *
 * history:
 *  05/12/12  eksc  cleaned up and modified for new bauplan
 */
 
  include_once('lib/news/news_helper.php');

  $ROW = getCGIparam('row', 'G', -1);

  $whatsnew = $mgdb->get('body')->load('templates/about/whatsnew.bau');

  $news_data = get_news_items();

  // Label left menu (news archives by year)
  $years = get_years($news_data);
  $labels = array();
  foreach($years as $year) {
	  array_push($labels, "$year News Archive");
  }
  array_push($labels, 'Entire News Archive');
  $whatsnew->get('whatsnew-table-row')->unroll('whatsnew-year', $labels);

  // Add news
  if ($ROW > 0 && $ROW < count($labels)) {
	  $index = $ROW - 1;
	  $whatsnew->get('whatsnew-title')->get('title')->replace($labels[$index]);
	  $whatsnew->get('whatsnew-news-table-row')
	      ->loop(build_loop_array(get_news_by_year($news_data, $years[$index])));
  }
  else { // default action: Entire News Archive
	  $whatsnew->get('whatsnew-title')->get('title')
	    ->replace('Entire News Archive');
	  $whatsnew->get('whatsnew-news-table-row')
	    ->loop(build_loop_array($news_data));
  }


function build_loop_array($news) {
	$loop_array = array();
	foreach ($news as $item) {
		$date = $item->date()->month() . " " . $item->date()->day() . ", " . $item->date()->year();
		
		array_push($loop_array, array(
			'date' => $date,
			'news' => $item->news(),
		));
	}
	
	return $loop_array;
}

?>