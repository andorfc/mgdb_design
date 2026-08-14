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

	//eksc
	public function getHTML() {
	    $html = "";
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

	private function scriptsToString() {
		$string = "";
		$this->resourceManifest->merge($this->template->_resourceManifest());
		foreach ($this->resourceManifest->items() as $resource) {
			$string .= $resource->value() . "\n";
		}

		return $string;
	}
}
?>
