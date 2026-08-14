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
	
	public function __construct($title="") {
		$this->resourceManifest  = new ResourceManifest();
		$this->resourceIncrement = 0;
		$this->preHTML  = new StringModifier();
		$this->title    = $title;
		$this->head     = new StringModifier();
		
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

	public function publish() {
    echo $this->getHTML();
	}
	
	//eksc
	public function getHTML() {
	    $html = "";
		$html .= $this->preHTML->value() . "\n";
		$html .= "<html>\n";
		$html .= "\t<head>\n";
		$html .= "\t\t<title>" . $this->title . "</title>\n";
		$html .= "\t\t" . $this->scriptsToString();
		$html .= "\t" . $this->head->value() . "\n";
		$html .= "\t</head>\n";
		$html .= "\t<body>\n";
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
