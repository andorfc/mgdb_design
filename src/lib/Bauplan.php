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
		$html .= "\t\t<title>" . $this->title . "</title>\n";
		$html .= "\t\t" . $this->scriptsToString();
		$html .= "\t" . $this->head->value() . "\n";
		$html .= "\t</head>\n";
		if ($this->bodyClass) {
			$html .= "\t<body class='" . htmlspecialchars($this->bodyClass, ENT_QUOTES) . "'>\n";
		}
		else {
			$html .= "\t<body>\n";
		}
		$html .= $this->template->getHTML();
		$html .= "\t</body>\n";
		$html .= "</html>";

		return $html;
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
