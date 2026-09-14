<?php
include_once('libbau/Template.php');
include_once('libbau/Resource.php');
include_once('libbau/ResourceManifest.php');
include_once('libbau/StringModifier.php');

//
// This is the API object which is the root of the tree
//
// @author: Bremen Braun
//
class Bauplan {
	private $resourceManifest;
	private $resourceIncrement;
	private $preHTML;
	private $title;
	private $head;
	private $scripts;
	private $template;
	private $modern;
	private $bodyClass;
	private $lang;

	public function __construct($title="") {
		$this->resourceManifest  = new ResourceManifest();
		$this->resourceIncrement = 0;
		$this->preHTML  = new StringModifier();
		$this->title    = $title;
		$this->head     = new StringModifier();
		$this->modern    = false;
		$this->bodyClass = "";
		$this->lang      = "en";

		$rootTemplate = new Template(null); # prevent naming conflicts by not giving this template a name
		$rootTemplate->_root($this); # root the template tree here
		$this->template = $rootTemplate;
	}

	public function title($title=null) {
		if ($title != null) {
			$this->title = $title;
		}

		return $this->title;
	}

	public function preHTML($string=null) {
		if ($string == null) {
			return $this->preHTML;
		}
		else {
			$this->preHTML->append($string);
		}
	}

	public function head($string=null) {
		if ($string == null) {
			return $this->head;
		}
		else {
			$this->head->append($string);
		}
	}

	public function template($template=null) {
		if ($template != null) {
			$this->template = $template;
			$template->_root($this);
		}

		return $this->template;
	}

	//
	// Opt a page in to the modernized document shell.
	//
	// Emits <!DOCTYPE html> (standards mode) and a responsive viewport meta tag,
	// and adds the 'mgdb-modern' class to <body> so the shared design system in
	// /css/mgdb-modern.css can scope every rule it applies.
	//
	// This is opt-in rather than sitewide on purpose: legacy pages were authored
	// against quirks-mode box sizing and a fixed 1280px wrapper, so switching them
	// to standards mode or to a device-width viewport would change their layout.
	// Modernized pages call this; every other page renders exactly as before.
	//
	public function modern($enable=true) {
		$this->modern = (bool)$enable;
		if ($this->modern) {
			$this->bodyClass('mgdb-modern');
		}

		return $this;
	}

	//
	// Append one or more space-separated classes to the <body> element.
	//
	public function bodyClass($class=null) {
		if ($class != null) {
			$this->bodyClass = trim($this->bodyClass . ' ' . $class);
		}

		return $this->bodyClass;
	}

	//
	// Document language, emitted as <html lang="...">. Defaults to 'en'.
	//
	public function lang($lang=null) {
		if ($lang != null) {
			$this->lang = $lang;
		}

		return $this->lang;
	}

	public function publish() {
    echo $this->getHTML();
	}

	//
	// Fill in the megamenu's BLAST link on pages that never set it.
	//
	// The Tools panel's BLAST href is $(blast_url), and the value comes from
	// BLAST_URL in conf/mgdb.conf. index.php replaces it, and so do the page
	// controllers that were written from a copy of one that does -- but 30 of
	// the 87 controllers loading the modern shell never did, and an unreplaced
	// Bauplan variable renders as the empty string. href="" resolves to the
	// current page, so on /data_center/stock, /expression, the project pages
	// and 27 others the menu's BLAST link quietly reloaded whatever the reader
	// was already on. The project pages' own "Run BLAST Search" buttons, which
	// use the same variable, went nowhere for the same reason.
	//
	// Defaulting here rather than in each of the 30 means the next page added
	// cannot ship without it. has() is the non-throwing lookup, so a template
	// tree with no $(blast_url) in it -- a page that does not draw the megamenu
	// -- is left untouched; hasBeenSet() means a controller that sets its own
	// value still wins, including amaizing_project.php, which has a fallback of
	// its own.
	//
	private function defaultBlastUrl() {
		if ($this->template == null || !$this->template->has('blast_url')) {
			return;
		}

		$node = $this->template->get('blast_url');
		if ($node->hasBeenSet()) {
			return;
		}

		global $system;
		$conf = is_array($system)
			? $system
			: (function_exists('getSystemInfo') ? getSystemInfo('mgdb.conf') : array());

		$url = (isset($conf['BLAST_URL']) && $conf['BLAST_URL'] !== '')
			? $conf['BLAST_URL']
			: '/BLAST';

		$node->replace($url);
	}

	//eksc
	public function getHTML() {
	    $html = "";
		$this->defaultBlastUrl();

		/* The page body has to be rendered before the head is written, because
		   the templates put their Open Graph tags at the top of the template --
		   which is inside <body>, where no crawler looks for them. See
		   liftSocialMeta(). */
		$body = $this->template->getHTML();
		$lifted = '';
		if ($this->modern) {
			$body = $this->liftSocialMeta($body, $lifted);
		}
		if ($this->modern) {
			$html .= "<!DOCTYPE html>\n";
		}
		$html .= $this->preHTML->value() . "\n";
		if ($this->modern) {
			$html .= "<html lang='" . htmlspecialchars($this->lang, ENT_QUOTES) . "'>\n";
		}
		else {
			$html .= "<html>\n";
		}
		$html .= "\t<head>\n";
		if ($this->modern) {
			$html .= "\t\t<meta name='viewport' content='width=device-width, initial-scale=1'>\n";
		}
		/* Escaped 2026-09-07. The title was written out raw, and several
		   controllers build one by concatenating a record id straight from the
		   URL -- controllers/community.php line 219, data_center.php 635 and
		   637, gene_center.php 259, pan_gene_center.php 134, all of the form
		   title('MaizeGDB ' . ucfirst(PAGE) . ' Record Page: ' . $id). That made
		   /person?id=</title><script>alert(1)</script> a live reflected XSS,
		   confirmed against the origin; Cloudflare's WAF hid it from the public
		   hostname, which is not the same as it being fixed.
		   Escaped here rather than at each call site so no future caller has to
		   remember. No caller passes markup or entities in a title -- checked
		   across every new Bauplan() and ->title() in controllers and lib --
		   so nothing double-encodes. bodyClass below was already escaped. */
		$html .= "\t\t<title>" . htmlspecialchars((string) $this->title, ENT_QUOTES, 'UTF-8') . "</title>\n";
		if ($this->modern) {
			$html .= $lifted;
			$html .= $this->socialHead($lifted);
		}
		$html .= "\t\t" . $this->scriptsToString();
		$html .= "\t" . $this->head->value() . "\n";
		$html .= "\t</head>\n";
		if ($this->bodyClass) {
			$html .= "\t<body class='" . htmlspecialchars($this->bodyClass, ENT_QUOTES) . "'>\n";
		}
		else {
			$html .= "\t<body>\n";
		}
		$html .= $body;
		$html .= "\t</body>\n";
		$html .= "</html>";

		return $html;
	}

	//
	// The tags every modern page needs and no page should have to remember.
	//
	// Link cards were blank everywhere because nothing on the site set
	// og:image: 97 templates carry og:title and og:description, and not one of
	// them named an image, so X, Bluesky, Discord and Slack had nothing to
	// show. The card, the favicons and the site-level Open Graph fields are the
	// same on every page, so they are written here rather than into a hundred
	// templates -- the same reasoning as the title escaping above.
	//
	// What is NOT here: og:title, og:description and og:url. Those are
	// per-page, the templates already set them, and a second copy would leave
	// two of each in the document for a crawler to choose between.
	//
	//
	// Move a template's Open Graph and Twitter tags out of the body and into
	// the head, and report which keys it set.
	//
	// 97 templates open with a block of <meta property="og:..."> before their
	// <main>. Bauplan renders a template into <body>, so every one of those
	// tags was being emitted around byte 65,000 of the document -- inside the
	// body, where X, Bluesky, Discord, Slack and Facebook do not read them.
	// The tags have been written correctly for years and have never been used.
	//
	// Only the run before the first <main> is considered, because that is where
	// the templates put them and because a <meta> inside the page's own prose
	// or a code sample is content, not metadata.
	//
	private function liftSocialMeta($body, &$lifted) {
		$split = stripos($body, '<main');
		if ($split === false) { $split = min(strlen($body), 8192); }
		$prefix = substr($body, 0, $split);
		$rest   = substr($body, $split);

		$pattern = '/[ \t]*<meta\s+(?:property|name)\s*=\s*["\'](?:og|twitter):[^"\']*["\'][^>]*>[ \t]*\r?\n?/i';
		if (!preg_match_all($pattern, $prefix, $m)) {
			$lifted = '';
			return $body;
		}

		$out = '';
		foreach ($m[0] as $tag) {
			$out .= "\t\t" . trim($tag) . "\n";
		}
		$lifted = $out;

		return preg_replace($pattern, '', $prefix) . $rest;
	}

	private function socialHead($lifted = '') {
		$host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] ? $_SERVER['HTTP_HOST'] : 'www.maizegdb.org';
		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
			$scheme = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https' : 'http';
		}
		$origin = $scheme . '://' . htmlspecialchars($host, ENT_QUOTES, 'UTF-8');

		/* Cache-busted from the file's own mtime, because a social network that
		   has cached a card will not fetch it again for a changed page -- only
		   for a changed URL. */
		$root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
		      ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
		$card_v = (int) @filemtime($root . '/images/social/maizegdb-card.png');
		$card = $origin . '/images/social/maizegdb-card.png' . ($card_v ? '?v=' . $card_v : '');

		/* Never write a key the template already set -- two of any og tag
		   leaves a crawler choosing between them. */
		$has = array();
		if ($lifted !== '' && preg_match_all('/(?:property|name)\s*=\s*["\']((?:og|twitter):[^"\']*)["\']/i', $lifted, $lm)) {
			foreach ($lm[1] as $key) { $has[strtolower($key)] = true; }
		}
		$put = function ($attr, $key, $value) use (&$has) {
			if (isset($has[strtolower($key)])) { return ''; }
			return "\t\t<meta " . $attr . "='" . $key . "' content='" . $value . "'>\n";
		};

		$h  = "";
		$h .= $put('property', 'og:image', $card);
		$h .= $put('property', 'og:image:width', '1200');
		$h .= $put('property', 'og:image:height', '630');
		$h .= $put('property', 'og:image:alt', 'MaizeGDB, the Maize Genetics and Genomics Database');
		$h .= $put('property', 'og:site_name', 'MaizeGDB');
		$h .= $put('property', 'og:locale', 'en_US');
		/* twitter:image is a fallback for readers that do not follow og:image;
		   X itself reads the og tags. twitter:card is per-page, because a page
		   that later wants a small card should be able to say so. */
		/* Fallbacks for the 58 modern templates that declare no og:title of
		   their own. Without them a card falls back to whatever the crawler can
		   scrape, which is usually the <title> anyway -- but saying it
		   explicitly is what stops a network inventing something worse, and it
		   is free. */
		$h .= $put('property', 'og:type', 'website');
		$h .= $put('property', 'og:title',
		           htmlspecialchars((string) $this->title, ENT_QUOTES, 'UTF-8'));

		/* The controller's own <meta name="description"> if it set one; that is
		   the sentence already written for search results. */
		if (!isset($has['og:description'])) {
			$headHtml = (string) $this->head->value();
			if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)["\']/i', $headHtml, $dm)) {
				$h .= $put('property', 'og:description', $dm[1]);
			}
		}

		$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
		$canonical = $origin . htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');
		$h .= $put('property', 'og:url', $canonical);

		$h .= $put('name', 'twitter:image', $card);
		/* A card with a 1200x630 image should say so, or X renders the small
		   square one. Templates that set it keep their own value. */
		$h .= $put('name', 'twitter:card', 'summary_large_image');

		/* The kernel, so a tab on this site is not the same icon as every other
		   MaizeGDB instance. PNG rather than .ico: every browser in use reads
		   it, and one source scales cleanly to all four sizes. */
		$h .= "\t\t<link rel='icon' type='image/png' sizes='32x32' href='/images/icons/kernel-32.png'>\n";
		$h .= "\t\t<link rel='icon' type='image/png' sizes='16x16' href='/images/icons/kernel-16.png'>\n";
		$h .= "\t\t<link rel='apple-touch-icon' sizes='180x180' href='/images/icons/kernel-180.png'>\n";
		$h .= "\t\t<meta name='theme-color' content='#235c37'>\n";

		return $h;
	}

	//
	// Append a cache-busting token to a locally served asset.
	//
	// Assets registered from a controller could always be versioned by hand,
	// but assets declared with 'include-css:' / 'include-js:' inside a .bau
	// template are plain strings that no PHP ever touches, so they were served
	// from a stable URL and stuck in the CDN cache for the full max-age after a
	// deploy. Versioning here covers both routes.
	//
	// The token is the file's modification time, which changes on every deploy.
	// Left untouched: absolute URLs to other hosts, protocol-relative URLs,
	// anything that already carries a query string, and paths with no matching
	// file on disk.
	//
	private function assetVersion($path) {
		if (!is_string($path) || $path === '') {
			return $path;
		}
		// Another host, protocol-relative, or already versioned by the caller.
		if (strpos($path, '//') !== false || strpos($path, '?') !== false) {
			return $path;
		}
		// Only site-absolute paths can be resolved against the document root.
		if ($path[0] !== '/') {
			return $path;
		}

		$file = $_SERVER['DOCUMENT_ROOT'] . $path;
		if (!is_file($file)) {
			return $path;
		}

		$mtime = @filemtime($file);
		if (!$mtime) {
			return $path;
		}

		return $path . '?v=' . $mtime;
	}

	public function includeCss($css_path) {
		$resource = new Resource($css_path, "<link rel='stylesheet' type='text/css' href='$css_path'/>");

		return $this->resourceManifest->add($resource);
	}

	public function includeCssText($text) {
		$resource = new Resource($this->autoincrement(), "<style>$text</style>");

		return $this->resourceManifest->add($resource);
	}

	public function includeScript($script_path, $type="text/javascript") {
		$resource = new Resource($script_path, "<script type='$type' src='$script_path'></script>");

		return $this->resourceManifest->add($resource);
	}

	public function includeScriptText($text, $type="text/javascript") {
		$resource = new Resource($this->autoincrement(), "<script type='$type'>$text</script>");

		return $this->resourceManifest->add($resource);
	}

	public function includeInHeader($text) {
		$resource = new Resource($text, $text);

		return $this->resourceManifest->add($resource);
	}

	private function autoincrement() {
		$this->resourceIncrement++;
		return $this->resourceIncrement;
	}

	//
	// Rewrite href/src attributes in an emitted resource tag so locally served
	// stylesheets and scripts carry a cache-busting token.
	//
	// This runs at emit time rather than in includeCss()/includeScript() for two
	// reasons. Assets declared with 'include-css:' / 'include-js:' inside a .bau
	// template never pass through those methods -- they arrive via the template's
	// own resource manifest -- so versioning there would miss them, which is the
	// case that kept a stale /js/mgdb-search.js in the CDN cache. Rewriting the
	// tag also leaves the manifest key untouched, so a script registered by both
	// a controller and a template still de-duplicates to a single tag.
	//
	private function versionMarkup($html) {
		return preg_replace_callback(
			"/(href|src)='([^']+)'/",
			function($matches) {
				return $matches[1] . "='" . $this->assetVersion($matches[2]) . "'";
			},
			$html
		);
	}

	private function scriptsToString() {
		$string = "";
		$this->resourceManifest->merge($this->template->_resourceManifest());
		foreach ($this->resourceManifest->items() as $resource) {
			$string .= $this->versionMarkup($resource->value()) . "\n";
		}

		return $string;
	}
}
?>
