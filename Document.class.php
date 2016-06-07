<?php
namespace phpjs;

use phpjs\DocumentType;
use phpjs\elements\html\HTMLBaseElement;
use phpjs\elements\html\HTMLHtmlElement;
use phpjs\elements\html\HTMLHeadElement;
use phpjs\elements\ElementFactory;
use phpjs\events\Event;
use phpjs\events\CustomEvent;
use phpjs\exceptions\DOMException;
use phpjs\exceptions\HierarchyRequestError;
use phpjs\exceptions\InvalidCharacterError;
use phpjs\exceptions\NotSupportedError;
use phpjs\urls\URLInternal;
use phpjs\Utils;

/**
 * @see https://dom.spec.whatwg.org/#interface-document
 * @see https://html.spec.whatwg.org/#document
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Document
 */
class Document extends Node
{
    use GetElementsBy;
    use NonElementParentNode;
    use ParentNode;

    const NO_QUIRKS_MODE = 1;
    const LIMITED_QUIRKS_MODE = 2;
    const QUIRKS_MODE = 3;
    const INERT_TEMPLATE_DOCUMENT = 0x1;

    protected static $mDefaultDocument = null;

    protected $mCharacterSet;
    protected $mContentType;
    protected $mFlags;
    protected $mInertTemplateDocument;
    protected $mMode;

    private $mCompatMode;
    private $mEvents;
    private $mImplementation;
    private $mNodeIteratorList;
    private $mURL;

    public function __construct()
    {
        parent::__construct();

        if (!static::$mDefaultDocument) {
            static::$mDefaultDocument = $this;
        }

        $this->mCharacterSet = 'utf-8';
        $this->mContentType = 'application/xml';
        $this->mFlags = 0;
        $this->mImplementation = new DOMImplementation($this);
        $this->mInertTemplateDocument = null;
        $this->mMode = self::NO_QUIRKS_MODE;
        $this->mNodeIteratorList = array();
        $this->mNodeType = self::DOCUMENT_NODE;
        $this->mOwnerDocument = null; // Documents own themselves.
        $this->mURL = null;
    }

    public function __destruct()
    {
        $this->mImplementation = null;
        $this->mNodeIteratorList = null;
        $this->mURL = null;

        if (self::$mRefCount == 1) {
            self::$mDefaultDocument = null;
        }

        parent::__destruct();
    }

    public function __get($aName)
    {
        switch ($aName) {
            case 'characterSet':
                return $this->mCharacterSet;
            case 'childElementCount':
                return $this->getChildElementCount();
            case 'children':
                return $this->getChildren();
            case 'contentType':
                return $this->mContentType;
            case 'doctype':
                foreach ($this->mChildNodes as $child) {
                    if ($child instanceof DocumentType) {
                        return $child;
                    }
                }

                return null;

            case 'documentElement':
                return $this->getFirstElementChild();
            case 'documentURI':
            case 'URL':
                return $this->getURL()->serializeURL();
            case 'firstElementChild':
                return $this->getFirstElementChild();
            case 'implementation':
                return $this->mImplementation;
            case 'lastElementChild':
                return $this->getLastElementChild();
            case 'origin':
                return $this->getURL()->getOrigin()->serializeAsUnicode();
            default:
                return parent::__get($aName);
        }
    }

    /**
     * Adopts the given Node and its subtree from a differnt Document allowing
     * the node to be used in this Document.
     *
     * @link https://dom.spec.whatwg.org/#dom-document-adoptnode
     *
     * @param  Node  $aNode The Node to be adopted.
     *
     * @return Node         The newly adopted Node.
     */
    public function adoptNode(Node $aNode)
    {
        if ($aNode instanceof Document) {
            throw new NotSupportedError;
        }

        if ($aNode instanceof ShadowRoot) {
            throw new HierarchyRequestError;
        }

        $this->doAdoptNode($aNode);

        return $aNode;
    }

    /**
     * Creates a new Attr object that with the given name.
     *
     * @see https://dom.spec.whatwg.org/#dom-document-createattribute
     *
     * @param string $aLocalName The name of the attribute.
     *
     * @return Attr
     *
     * @throws InvalidCharacterError
     */
    public function createAttribute($aLocalName)
    {
        $localName = Utils::DOMString($aLocalName);

        // If localName does not match the Name production in XML, then
        // throw an InvalidCharacterError.
        if (!preg_match( Namespaces::NAME_PRODUCTION, $localName)) {
            throw new InvalidCharacterError();
        }

        $localName = $this instanceof HTMLDocument ?
            Utils::toASCIILowercase($localName) : $localName;

        return new Attr($localName, '');
    }

    /**
     * Creates a new Attr object with the given namespace and the name and
     * prefix for the given namespace.
     *
     * @see https://dom.spec.whatwg.org/#dom-document-createattributens
     *
     * @param string $aNamespace The attribute's namespace.
     *
     * @param string $aQualifiedName The attribute's qualified name.
     *
     * @return Attr
     *
     * @throws NamespaceError
     *
     * @throws InvalidCharacterError
     */
    public function createAttributeNS($aNamespace, $aQualifiedName)
    {
        try {
            list(
                $namespace,
                $prefix,
                $localName
            ) = Namespaces::validateAndExtract(
                Utils::DOMString($aNamespace, false, true),
                Utils::DOMString($aQualifiedName)
            );
        } catch (DOMException $e) {
            throw $e;
        }

        return new Attr($localName, '', $namespace, $prefix);
    }

    public function createComment($aData)
    {
        $node = new Comment($aData);
        $node->mOwnerDocument = $this;

        return $node;
    }

    public function createDocumentFragment()
    {
        $node = new DocumentFragment();
        $node->mOwnerDocument = $this;

        return $node;
    }

    /**
     * Creates an HTMLElement with the specified tag name.
     *
     * @link https://dom.spec.whatwg.org/#dom-document-createelement
     *
     * @param string $aLocalName The name of the element to create.
     *
     * @return HTMLElement A known HTMLElement or HTMLUnknownElement.
     */
    public function createElement($aLocalName)
    {
        $localName = Utils::DOMString($aLocalName);

        // If localName does not match the Name production, then throw an
        // InvalidCharacterError.
        if (!preg_match(Namespaces::NAME_PRODUCTION, $localName)) {
            throw new InvalidCharacterError();
        }

        // If the context object is an HTML document, let localName be converted
        // to ASCII lowercase.
        if ($this instanceof HTMLDocument) {
            $localName = Utils::toASCIILowercase($localName);
        }

        // Let namespace be the HTML namespace, if the context object’s content
        // type is "text/html" or "application/xhtml+xml", and null otherwise.
        switch ($this->mContentType) {
            case 'text/html':
            case 'application/xhtml+xml':
                $namespace = Namespaces::HTML;

                break;

            default:
                $namespace = null;
        }

        try {
            $element = ElementFactory::create(
                $this,
                $localName,
                $namespace,
                null
            );
        } catch (DOMException $e) {
            throw $e;
        }

        return $element;
    }

    /**
     * Creates an Element in a particular namespace.
     *
     * @see https://dom.spec.whatwg.org/#dom-document-createelementns
     *
     * @param string|null $aNamespace The element's namespace.
     *
     * @param string $aQualifiedName The element's qualified name.
     *
     * @return Element
     *
     * @throws InvalidCharacterError
     *
     * @throws NamespaceError
     */
    public function createElementNS($aNamespace, $aQualifiedName)
    {
        return ElementFactory::createNS(
            $this,
            Utils::DOMString($aNamespace, false, true),
            Utils::DOMString($aQualifiedName)
        );
    }

    /**
     * Creates a new Event of the specified type and returns it.
     *
     * @link https://dom.spec.whatwg.org/#dom-document-createevent
     *
     * @param string $aInterface The type of event interface to be created.
     *
     * @return Event
     *
     * @throws NotSupportedError
     */
    public function createEvent($aInterface)
    {
        $constructor = null;
        $interface = strtolower(Utils::DOMString($aInterface));

        switch ($interface) {
            case 'customevent':
                $constructor = 'CustomEvent';

                break;

            case 'event':
            case 'events':
            case 'htmlevents':
                $constructor = 'Event';
        }

        if (!$constructor) {
            throw new NotSupportedError;
        }

        $event = new $constructor('');
        $event->_unsetFlag(Event::EVENT_INITIALIZED);

        return $event;
    }

    /**
     * Returns a new NodeIterator object, which represents an iterator over the
     * members of a list of the nodes in a subtree of the DOM.
     *
     * @param Node $aRoot The root node of the iterator object.
     *
     * @param int $aWhatToShow Optional. A bitmask of NodeFilter constants
     *     allowing the user to filter for specific node types.
     *
     * @param  callable|null $aFilter A user defined function to determine
     *     whether or not to accept a node that has passed the whatToShow check.
     *
     * @return NodeIterator
     */
    public function createNodeIterator(
        Node $aRoot,
        $aWhatToShow = NodeFilter::SHOW_ALL,
        callable $aFilter = null
    ) {
        $iter = new NodeIterator($aRoot, $aWhatToShow, $aFilter);
        $this->mNodeIteratorList[] = $iter;

        return $iter;
    }

    public function createProcessingInstruction($aTarget, $aData)
    {
        $target = Utils::DOMString($aTarget);

        // If target does not match the Name production, then throw an
        // InvalidCharacterError.
        if (!preg_match(Namespaces::NAME_PRODUCTION, $target)) {
            throw new InvalidCharacterError();
        }

        $data = Utils::DOMString($aData);

        if (strpos($data, '?>') !== false) {
            throw new InvalidCharacterError();
        }

        $pi = new ProcessingInstruction($target, $data);
        $pi->mOwnerDocument = $this;

        return $pi;
    }

    public function createRange()
    {
        $range = new Range();
        $range->setStart($this, 0);
        $range->setEnd($this, 0);

        return $range;
    }

    public function createTextNode($aData)
    {
        $node = new Text($aData);
        $node->mOwnerDocument = $this;

        return $node;
    }

    /**
     * Returns a new TreeWalker object, which represents the nodes of a document
     * subtree and a position within them.
     *
     * @param Node $aRoot The root node of the DOM subtree being traversed.
     *
     * @param int $aWhatToShow Optional.  A bitmask of NodeFilter constants
     *     allowing the user to filter for specific node types.
     *
     * @param callable|null $aFilter A user defined function to determine
     *     whether or not to accept a node that has passed the whatToShow check.
     *
     * @return TreeWalker
     */
    public function createTreeWalker(
        Node $aRoot,
        $aWhatToShow = NodeFilter::SHOW_ALL,
        callable $aFilter = null
    ) {
        return new TreeWalker($aRoot, $aWhatToShow, $aFilter);
    }

    /**
     * Removes a node from its parent and adopts it and all its children.
     *
     * @internal
     *
     * @see https://dom.spec.whatwg.org/#concept-node-adopt
     *
     * @param Node $aNode The node being adopted.
     */
    public function doAdoptNode(Node $aNode)
    {
        $oldDocument = $aNode->mOwnerDocument;

        if ($aNode->mParentNode) {
            $aNode->mParentNode->removeNode($aNode);
        }

        if ($this !== $oldDocument) {
            $iter = new NodeIterator($aNode, NodeFilter::SHOW_ALL);

            while (($node = $iter->nextNode())) {
                $node->mOwnerDocument = $this;
            }

            // For each descendant in node’s inclusive descendants, in
            // tree order, run the adopting steps with descendant and
            // oldDocument.
            $iter = new NodeIterator($aNode);

            while (($descendant = $iter->nextNode())) {
                if (method_exists($descendant, 'doAdoptingSteps')) {
                    $descendant->doAdoptingSteps($oldDocument);
                }
            }
        }
    }

    /**
     * Returns the special proxy document responsible for owning all of a
     * template element's content's children.
     *
     * @internal
     *
     * @see https://html.spec.whatwg.org/multipage/scripting.html#appropriate-template-contents-owner-document
     *
     * @return Document
     */
    public function getAppropriateTemplateContentsOwnerDocument()
    {
        $doc = $this;

        if (!($this->mFlags & self::INERT_TEMPLATE_DOCUMENT)) {
            if (!$this->mInertTemplateDocument) {
                $newDoc = new static();
                $newDoc->mFlags |= self::INERT_TEMPLATE_DOCUMENT;
                $this->mInertTemplateDocument = $newDoc;
            }

            $doc = $this->mInertTemplateDocument;
        }

        return $doc;
    }

    /**
     * Gets the document's base URL, which is either the document's address or
     * the value of a base element's href attribute.
     *
     * @internal
     *
     * @see https://html.spec.whatwg.org/multipage/infrastructure.html#document-base-url
     *
     * @return URLInternal
     */
    public function getBaseURL()
    {
        $head = $this->getHeadElement();
        $base = null;

        if ($head) {
            // A base element is only valid if it is the first base element
            // within the head element.
            foreach ($head->mChildNodes as $child) {
                $isValidBase = $child instanceof HTMLBaseElement &&
                    $child->hasAttribute('href');

                if ($isValidBase) {
                    $base = $child;
                    break;
                }
            }
        }

        // We don't have a valid base element to use as a base URL, use the
        // fallback base URL.
        if (!$base) {
            return $this->getFallbackBaseURL();
        }

        return $base->getFrozenBaseURL();
    }

    /**
     * Returns the first document created, which is assumed to be the global
     * document.  This global document is the owning document for objects
     * instantiated using its constructor.  These objects are DocumentFragment,
     * Text, Comment, and ProcessingInstruction.
     *
     * @internal
     *
     * @return Document|null Returns the global document.  If null is returned,
     *     then no document existed before the user attempted to instantiate an
     *     object that has an owning document.
     */
    public static function _getDefaultDocument()
    {
        return static::$mDefaultDocument;
    }

    public function getFallbackBaseURL()
    {
        // TODO: If the Document is an iframe srcdoc document, then return the
        // document base URL of the Document's browsing context's browsing
        // context container's node document and abort these steps.

        // TODO: If the document's address is about:blank, and the Document's
        // browsing context has a creator browsing context, then return the
        // creator base URL and abort these steps.

        return $this->getURL();
    }

    /**
     * Gets the flags set on the document.
     *
     * @internal
     *
     * @return int
     */
    public function getFlags()
    {
        return $this->mFlags;
    }

    /**
     * Gets the value of the document's mode.
     *
     * @internal
     *
     * @return int
     */
    public function _getMode()
    {
        return $this->mMode;
    }

    public function _getNodeIteratorCollection()
    {
        return $this->mNodeIteratorList;
    }

    public function importNode(Node $aNode, $aDeep = false)
    {
        if ($aNode instanceof Document || $aNode instanceof ShadowRoot) {
            throw new NotSupportedError();
        }

        return $aNode->doCloneNode(null, $aDeep);
    }

    public static function prettyPrintTree(Node $aNode)
    {
        if (
            !($aNode instanceof elements\Element) &&
            !($aNode instanceof Document) &&
            !($aNode instanceof DocumentFragment)
        ) {
            throw new InvalidNodeTypeError();
        }

        $s = '<ul class="level">';
        $node = $aNode instanceof elements\html\HTMLTemplateElement ?
            $aNode->content : $aNode;

        foreach ($node->mChildNodes as $currentNode) {
            switch ($currentNode->mNodeType) {
                case self::ELEMENT_NODE:
                    switch ($currentNode->namespaceURI) {
                        case Namespaces::HTML:
                        case Namespaces::MATHML:
                        case Namespaces::SVG:
                            $tagname = $currentNode->localName;

                            break;

                        default:
                            $tagname = $currentNode->tagName;
                    }

                    $s .= '<li>&lt;' . $tagname;

                    foreach ($currentNode->attributes as $attr) {
                        $s .= ' ' . $attr->name;
                        $s .= '="' .
                            htmlentities(parser\html\HTMLParser::escapeHTMLString(
                                $attr->value,
                                true
                            ) . '"');
                    }

                    $s .= '&gt;';
                    $localName = $currentNode->localName;

                    // If the current node's local name is a known void element,
                    // then move on to the current node's next sibling, if any.
                    switch ($localName) {
                        case 'area':
                        case 'base':
                        case 'basefont':
                        case 'bgsound':
                        case 'br':
                        case 'col':
                        case 'embed':
                        case 'frame':
                        case 'hr':
                        case 'img':
                        case 'input':
                        case 'keygen':
                        case 'link':
                        case 'menuitem':
                        case 'meta':
                        case 'param':
                        case 'source':
                        case 'track':
                        case 'wbr':
                            continue 2;
                    }

                    if (
                        ($localName == 'pre' ||
                            $localName == 'textarea' ||
                            $localName == 'listing') &&
                        ($firstChild = $currentNode->mFirstChild) instanceof Text
                    ) {
                        if ($firstChild->data === "\t") {
                            $s .= "\n";
                        }
                    }

                    $s .= self::prettyPrintTree($currentNode);
                    $s .= '&lt;/' . $tagname . '&gt;</li>';

                    break;

                case self::TEXT_NODE:
                    $s .= '<li>';

                    switch ($currentNode->mParentNode->localName) {
                        case 'style':
                        case 'script':
                        case 'xmp':
                        case 'iframe':
                        case 'noembed':
                        case 'noframes':
                        case 'plaintext':
                        case 'noscript':
                            $s .= $currentNode->data;

                            break;

                        default:
                            $s .= htmlspecialchars($currentNode->data);
                    }

                    $s .= '</li>';

                    break;

                case self::COMMENT_NODE:
                    $s .= '<li>&lt;!--' . $currentNode->data . '--&gt;</li>';

                    break;

                case self::PROCESSING_INSTRUCTION_NODE:
                    $s .= '<li>&lt;?' . $currentNode->target . ' ';
                    $s .= $currentNode->data . '&gt;</li>';

                    break;

                case self::DOCUMENT_TYPE_NODE:
                    $s .= '<li>&lt;!DOCTYPE ' . $currentNode->name;
                    $s .= '&gt;</li>';

                    break;
            }
        }

        $s .= '</ul>';

        return $s;
    }

    public function _printTree()
    {
        return $this->_traverseTree($this->mChildNodes, 0);
    }

    /**
     * @internal
     *
     * Sets the document's character set.
     *
     * @param string $aCharacterSet The document's character set
     */
    public function _setCharacterSet($aCharacterSet)
    {
        if (!is_string($aCharacterSet)) {
            return;
        }

        $this->mCharacterSet = $aCharacterSet;
    }

    /**
     * Sets the document's content type.
     *
     * @internal
     *
     * @param string $aType The MIME content type of the document.
     */
    public function _setContentType($aType)
    {
        $this->mContentType = $aType;
    }

    /**
     * Sets flags on the document.
     *
     * @internal
     *
     * @param int $aFlag Bitwise flags.
     */
    public function setFlags($aFlag)
    {
        $this->mFlags |= $aFlag;
    }

    /**
     * Sets the document's mode.
     *
     * @internal
     *
     * @param int $aMode An integer representing the current mode.
     */
    public function _setMode($aMode)
    {
        $this->mMode = $aMode;
    }

    /**
     * Unsets bitwise flags on the document.
     *
     * @internal
     *
     * @param int $aFlag Bitwise flags.
     */
    public function unsetFlags($aFlag)
    {
        $this->mFlags &= ~$aFlag;
    }

    /**
     * Gets the document's head element. The document's head element is the
     * first child of the html element that is a head element.
     *
     * @internal
     *
     * @see https://html.spec.whatwg.org/multipage/dom.html#dom-document-head
     *
     * @return HTMLHeadElement|null
     */
    protected function getHeadElement()
    {
        $docElement = $this->getFirstElementChild();

        if ($docElement && $docElement instanceof HTMLHtmlElement) {
            // Get the first child in the document element that is a head
            // element.
            foreach ($docElement->mChildNodes as $child) {
                if ($child instanceof HTMLHeadElement) {
                    return $child;
                }
            }
        }

        return null;
    }

    /**
     * @see Node::getNodeName
     */
    protected function getNodeName()
    {
        return '#document';
    }

    /**
     * @see Node::getNodeValue
     */
    protected function getNodeValue()
    {
        return null;
    }

    /**
     * @see Node::getTextContent
     */
    protected function getTextContent()
    {
        return null;
    }

    /**
     * Gets the Document's URL address.
     *
     * @internal
     *
     * @see https://dom.spec.whatwg.org/#concept-document-url
     *
     * @return string
     */
    protected function getURL()
    {
        if (!isset($this->mURL)) {
            $ssl = isset($_SERVER['HTTPS']) && $_SERVER["HTTPS"] == 'on';
            $port = in_array($_SERVER['SERVER_PORT'], array(80, 443)) ?
                '' : ':' . $_SERVER['SERVER_PORT'];
            $url = ($ssl ? 'https' : 'http') . '://' . $_SERVER['SERVER_NAME'] .
                $port . $_SERVER['REQUEST_URI'];

            $this->mURL = URLInternal::basicURLParser($url);
        }


        return $this->mURL;
    }

    /**
     * @see Node::setNodeValue
     */
    protected function setNodeValue($aNewValue)
    {
        // Do nothing.
    }

    /**
     * @see Node::setTextContent
     */
    protected function setTextContent($aNewValue)
    {
        // Do nothing.
    }

    private function _traverseTree($aNodes, $aLevel = 0)
    {
        if (empty($aNodes)) {
            return '';
        }

        $html = '<div class="tree-level">';
        foreach ($aNodes as $node) {
            $name = $node->nodeName ?
                strtolower($node->nodeName) : get_class($node);
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
}
