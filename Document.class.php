<?php
// https://developer.mozilla.org/en-US/docs/Web/API/Document
// https://html.spec.whatwg.org/#document

require_once 'Node.class.php';
require_once 'ParentNode.class.php';
require_once 'DOMImplementation.class.php';
require_once 'DocumentType.class.php';
require_once 'Attr.class.php';
require_once 'DocumentFragment.class.php';
require_once 'Event.class.php';
require_once 'Text.class.php';
require_once 'NonElementParentNode.class.php';
require_once 'Comment.class.php';
require_once 'URL.class.php';
require_once 'NodeFilter.class.php';
require_once 'NodeIterator.class.php';
require_once 'TreeWalker.class.php';

class Document extends Node {
	use ParentNode, NonElementParentNode;

	protected static $mDefaultDocument = null;

	protected $mCharacterSet;
	protected $mContentType;
	protected $mDoctype; // DocumentType
	protected $mDocumentElement;

	private $mCompatMode;
	private $mEvents;
	private $mImplementation;
	private $mURL;

	public function __construct() {
		parent::__construct();

		if (!static::$mDefaultDocument) {
			static::$mDefaultDocument = $this;
		}

		$this->mContentType = '';
		$this->mDoctype = new DocumentType('', '', '');
		$this->mDocumentElement = null;
		$this->mEvents = array();
		$this->mImplementation = new iDOMImplementation();
		$this->mNodeName = '#document';
		$this->mNodeType = Node::DOCUMENT_NODE;
		$this->mOwnerDocument = null; // Documents own themselves.

		$ssl = isset($_SERVER['HTTPS']) && $_SERVER["HTTPS"] == 'on';
		$port = in_array($_SERVER['SERVER_PORT'], array(80, 443)) ? '' : ':' . $_SERVER['SERVER_PORT'];
		$url = ($ssl ? 'https' : 'http') . '://' . $_SERVER['SERVER_NAME'] . $port . $_SERVER['REQUEST_URI'];

		$this->mURL = new URL($url);
	}

	public function __get($aName) {
		switch ($aName) {
			case 'baseURI':
				return $this->mURL->href;
			case 'characterSet':
				return $this->mCharacterSet;
			case 'childElementCount':
				return $this->getChildElementCount();
			case 'children':
				return $this->getChildren();
			case 'contentType':
				return $this->mContentType;
			case 'doctype':
				return $this->mDoctype;
			case 'documentElement':
				return $this->mDocumentElement;
			case 'documentURI':
			case 'URL':
				return $this->mURL->href;
			case 'firstElementChild':
				return $this->getFirstElementChild();
			case 'implementation':
				return $this->mImplementation;
			case 'lastElementChild':
				return $this->getLastElementChild();
			case 'origin':
				return $this->mURL->origin;
			default:
				return parent::__get($aName);
		}
	}

	public function adoptNode(Node $aNode) {
		if ($aNode instanceof Document) {
			throw new NotSupportedError;
		}

		if ($aNode->parentNode) {
			$aNode->parentNode->removeChild($aNode);
		}

		$this->_adoptNode($aNode);

		return $aNode;
	}

	public function createElement( $aTagName ) {
		switch($aTagName) {
			case 'a':
				$interfaceName = 'Anchor';

				break;

			case 'br':
				$interfaceName = 'BR';

				break;

			case 'datalist':
				$interfaceName = 'DataList';

				break;

			case 'dl':
				$interfaceName = 'DList';

				break;

			case 'fieldset':
				$interfaceName = 'FieldSet';

				break;

			case 'hr':
				$interfaceName = 'HR';

				break;

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$interfaceName = 'Heading';

				break;

			case 'iframe':
				$interfaceName = 'IFrame';

				break;

			case 'ins':
			case 'del':
				$interfaceName = 'Mod';

				break;

			case 'li':
				$interfaceName = 'LI';

				break;

			case 'ol':
				$interfaceName = 'OList';

				break;

			case 'optgroup':
				$interfaceName = 'OptGroup';

				break;

			case 'p':
				$interfaceName = 'Paragraph';

				break;

			case 'blockquote':
			case 'cite':
			case 'q':
				$interfaceName = 'Quote';

				break;

			case 'caption':
				$interfaceName = 'TableCaption';

				break;

			case 'td':
			case 'th':
				$interfaceName = 'TableCell';

				break;

			case 'col':
			case 'colgroup':
				$interfaceName = 'TableCol';

				break;

			case 'tr':
				$interfaceName = 'TableRow';

				break;

			case 'tbody':
			case 'thead':
			case 'tfoot':
				$interfaceName = 'TableSection';

				break;

			case 'textarea':
				$interfaceName = 'TextArea';

				break;

			case 'ul':
				$interfaceName = 'UList';

				break;

			case 'area':
			case 'audio':
			case 'base':
			case 'body':
			case 'button':
			case 'canvas':
			case 'data':
			case 'div':
			case 'embed':
			case 'form':
			case 'head':
			case 'html':
			case 'image':
			case 'input':
			case 'keygen':
			case 'label':
			case 'legend':
			case 'link':
			case 'map':
			case 'meta':
			case 'meter':
			case 'object':
			case 'option':
			case 'output':
			case 'param':
			case 'picture':
			case 'pre':
			case 'progress':
			case 'script':
			case 'select':
			case 'source':
			case 'span':
			case 'style':
			case 'table':
			case 'time':
			case 'title':
			case 'track':
			case 'video':
				$interfaceName = ucfirst(strtolower($aTagName));

				break;

			default:
				if (@file_exists('HTMLElement/' . $aTagName . 'Element.class.php')) {
					$interfaceName = ucfirst(strtolower($aTagName));
				} else {
					$interfaceName = 'Unknown';
				}
		}

		$className = 'HTML' . $interfaceName . 'Element';
		require_once 'HTMLElement/' . $className . '.class.php';

		$node = new $className();
		$node->mOwnerDocument = $this;

		return $node;
	}

	public function createDocumentFragment() {
		$node = new DocumentFragment();
		$node->mOwnerDocument = $this;

		return $node;
	}

	public function createComment($aData) {
		$node = new Comment($aData);
		$node->mOwnerDocument = $this;

		return $node;
	}

	public function createEvent($aEventType) {
		return new Event($aEventType);
	}

	/**
	 * Returns a new NodeIterator object, which represents an iterator over the members of a list of the nodes in a
	 * subtree of the DOM.
	 * @param  Node          	$aRoot       The root node of the iterator object.
	 * @param  int 				$aWhatToShow Optional.  A bitmask of NodeFilter constants allowing the user
	 *                              			to filter for specific node types.
	 * @param  callable|null 	$aFilter     A user defined function to determine whether or not to accept a node that has
	 *                                     		passed the whatToShow check.
	 * @return NodeIterator
	 */
	public function createNodeIterator(Node $aRoot, $aWhatToShow = NodeFilter::SHOW_ALL, callable $aFilter = null) {
		return new NodeIterator($aRoot, $aWhatToShow, $aFilter);
	}

	public function createTextNode($aData) {
		$node = new Text($aData);
		$node->mOwnerDocument = $this;

		return $node;
	}

	/**
	 * Returns a new TreeWalker object, which represents the nodes of a document subtree and a position within them.
	 * @param  Node          	$aRoot       The root node of the DOM subtree being traversed.
	 * @param  int        		$aWhatToShow Optional.  A bitmask of NodeFilter constants allowing the user
	 *                                   		to filter for specific node types.
	 * @param  callable|null 	$aFilter     A user defined function to determine whether or not to accept a node that has
	 *                                     		passed the whatToShow check.
	 * @return TreeWalker
	 */
	public function createTreeWalker(Node $aRoot, $aWhatToShow = NodeFilter::SHOW_ALL, callable $aFilter = null) {
		return new TreeWalker($aRoot, $aWhatToShow, $aFilter);
	}

	public function getElementsByClassName($aClassName) {
		$nodeList = array();
		$classNames = explode(' ', preg_replace('/\s+/', ' ', trim($aClassName)));

		$tw = $this->createTreeWalker($this, NodeFilter::SHOW_ELEMENT,
				function($aNode) use ($classNames) {
					$hasClassName = false;

					foreach ($classNames as $className) {
						if ($hasClassName = $aNode->classList->contains($className)) {
							break;
						}
					}

					return $hasClassName ? NodeFilter::FILTER_ACCEPT : NodeFilter::FILTER_SKIP;
				});

		while ($node = $tw->nextNode()) {
			$nodeList[] = $node;
		}

		return $nodeList;
	}

	/**
	 * Returns an array of Elements with the specified tagName.
	 * @param  string $aTagName The tagName to search for.
	 * @return array           	A list of Elements with the specified tagName.
	 */
	public function getElementsByTagName($aTagName) {
		$nodeList = array();

		$tw = $this->createTreeWalker($this, NodeFilter::SHOW_ELEMENT,
			function($aNode) use ($aTagName) {
				return strcasecmp($aNode->tagName, $aTagName) == 0 ? NodeFilter::FILTER_ACCEPT : NodeFilter::FILTER_SKIP;
			});

		while ($node = $tw->nextNode()) {
			$nodeList[] = $node;
		}

		return $nodeList;
	}

	public function importNode(Node $aNode, $aDeep = false) {
		if ($aNode instanceof Document) {
			throw new NotSupportedError;
		}

		$clone = $aNode->cloneNode($aDeep);
		$this->adoptNode($clone);

		return $clone;
	}

	/**
	 * @internal
	 * Returns the first document created, which is assumed to be the global
	 * document.  This global document is the owning document for objects instantiated
	 * using its constructor.  These objects are DocumentFragment, Text, Comment, and
	 * ProcessingInstruction.
	 * @return Document|null Returns the global document.  If null is returned, then no
	 *                          document existed before the user attempted to instantiate
	 *                          an object that has an owning document.
	 */
	public static function _getDefaultDocument() {
		return static::$mDefaultDocument;
	}

	public function _printTree() {
		return $this->_traverseTree($this->mChildNodes, 0);
	}

	private function _traverseTree($aNodes, $aLevel = 0) {
		if (empty($aNodes)) {
			return '';
		}

		$html = '<div class="tree-level">';
		foreach ($aNodes as $node) {
			$name = $node->nodeName ? strtolower($node->nodeName) : get_class($node);
			$html .= '<div class="tree-branch">';
			$html .= htmlspecialchars('<' . $name);
			if ($node instanceof Element) {
				foreach($node->attributes as $attribute) {
					$html .= ' ' . $attribute->name;

					if (!Attr::_isBool($attribute->name)) {
						$html .= '="' . $attribute->value . '"';
					}
				}
			}
			$html .= '></div>';
			$html .= $this->_traverseTree($node->childNodes, ++$aLevel);
		}
		$html .= '</div>';

		return $html;
	}

	public function prettyPrintTree($aNode = null) {
		$node = ($aNode instanceof Node ? [$aNode] : (is_array($aNode) ? $aNode : [$this]));

		if (empty($node)) {
			return '';
		}

		$html = '<ul class="level">';

		foreach ($node as $childNode) {
			switch ($childNode->nodeType) {
				case Node::ELEMENT_NODE:
					$tagName = strtolower($childNode->nodeName);
					$html .= '<li>&lt;' . $tagName;

					foreach($childNode->attributes as $attribute) {
						$html .= ' ' . $attribute->name;

						if (!Attr::_isBool($attribute->name)) {
							$html .= '="' . $attribute->value . '"';
						}
					}

					$html .= '&gt;' . $this->prettyPrintTree($childNode->childNodes);

					if (!$childNode->_isEndTagOmitted()) {
						$html .= '&lt;/' . $tagName . '&gt;</li>';
					}

					break;

				case Node::TEXT_NODE:
					$html .= '<li>' . $childNode->data . '<li>';

					break;

				case Node::PROCESSING_INSTRUCTION_NODE:
					// TODO
					break;

				case Node::COMMENT_NODE:
					$html .= '<li>&lt;!-- ' . $childNode->data . ' --&gt;</li>';

					break;

				case Node::DOCUMENT_TYPE_NODE:
					$html .= '<li>' . htmlentities($childNode->toHTML()) . '</li>';

					break;

				case Node::DOCUMENT_NODE:
				case Node::DOCUMENT_FRAGMENT_NODE:
					$html = $this->prettyPrintTree($childNode->childNodes);

					break;

				default:
					# code...
					break;
			}
		}

		$html .= '</ul>';

		return $html;
	}

	private function _adoptNode($aNode) {
		$aNode->mOwnerDocument = $this;

		foreach ($aNode->childNodes as $node) {
			$this->_adoptNode($node);
		}
	}
}
