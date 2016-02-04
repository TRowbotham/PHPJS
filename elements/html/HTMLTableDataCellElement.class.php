<?php
namespace phpjs\elements\html;

/**
 * Represents the HTML table cell element <td>.
 *
 * @link https://html.spec.whatwg.org/#the-td-element
 */
class HTMLTableDataCellElement extends HTMLTableCellElement {
    public function __construct($aLocalName, $aNamespaceURI, $aPrefix = null) {
        parent::__construct($aLocalName, $aNamespaceURI, $aPrefix);
    }
}