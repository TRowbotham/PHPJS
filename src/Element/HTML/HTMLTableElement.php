<?php

declare(strict_types=1);

namespace Rowbot\DOM\Element\HTML;

use Rowbot\DOM\Exception\HierarchyRequestError;
use Rowbot\DOM\Exception\IndexSizeError;

use function array_merge;
use function count;

/**
 * Represents the HTML table element <table>.
 *
 * @see https://html.spec.whatwg.org/multipage/tables.html#the-table-element
 *
 * @property \Rowbot\DOM\Element\HTML\HTMLTableCaptionElement|null $caption Upon getting, it returns the first <caption>
 *                                                                          element in the table or null. Upon setting,
 *                                                                          if the value is an HTMLTableCaptionElement
 *                                                                          the first <caption> element in the table is
 *                                                                          removed and replaced with the given one. If
 *                                                                          the value is null, the first <caption>
 *                                                                          element is removed, if any.
 * @property \Rowbot\DOM\Element\HTML\HTMLTableSectionElement|null $tHead   Upon getting, it returns the first <thead>
 *                                                                          element in the table or null. Upon setting,
 *                                                                          if the value is an HTMLTableSectionElement
 *                                                                          and its tagName is THEAD or the value is
 *                                                                          null, the first <thead> element, if any, is
 *                                                                          removed from the table.  If  the value is
 *                                                                          HTMLTableSectionElement and its tagName is
 *                                                                          THEAD, the supplied value is inserted into
 *                                                                          the table before the first element that is
 *                                                                          neither a <caption>, <colgroup>, or <col>
 *                                                                          element. Throws a HierarchyRequestError if
 *                                                                          the given value is not null or
 *                                                                          HTMLTableSectionElement with a tagName of
 *                                                                          THEAD.
 * @property \Rowbot\DOM\Element\HTML\HTMLTableSectionElement|null $tFoot   Upon getting, it returns the first <tfoot>
 *                                                                          element in the table or null. Upon setting,
 *                                                                          if the value is an HTMLTableSectionElement
 *                                                                          and its tagName is TFOOT or the value is
 *                                                                          null, the first <tfoot> element, if any, is
 *                                                                          removed from the table. If the value is
 *                                                                          HTMLTableSectionElement and its tagName is
 *                                                                          TFOOT, the supplied value is inserted into
 *                                                                          the table before the first element that is
 *                                                                          neither a <caption>, <colgroup>, <col>, or
 *                                                                          <thead> element. Throws a
 *                                                                          HierarchyRequestError if the given value is
 *                                                                          not null or HTMLTableSectionElement with a
 *                                                                          tagName of TFOOT.
 *
 * @property-read list<\Rowbot\DOM\Element\HTML\HTMLTableRowElement>     $rows    Returns a list of all the <tr>
 *                                                                                elements, in order, that are in the
 *                                                                                table.
 * @property-read list<\Rowbot\DOM\Element\HTML\HTMLTableSectionElement> $tBodies Returns a list of all the <tbody>
 *                                                                                elements, in order, that are in the
 *                                                                                table.
 */
class HTMLTableElement extends HTMLElement
{
    public function __get(string $name)
    {
        switch ($name) {
            case 'caption':
                $caption = $this->shallowGetElementsByTagName('caption');

                return count($caption) ? $caption[0] : null;

            case 'rows':
                $thead = $this->shallowGetElementsByTagName('thead');
                $tfoot = $this->shallowGetElementsByTagName('tfoot');
                $collection = [];

                if (count($thead)) {
                    $collection = array_merge(
                        $collection,
                        $thead[0]->shallowGetElementsByTagName('tr')
                    );
                }

                foreach ($this->childNodes as $node) {
                    if ($node instanceof HTMLTableRowElement) {
                        $collection[] = $node;
                    } elseif (
                        $node instanceof HTMLTableSectionElement
                        && $node->tagName === 'TBODY'
                    ) {
                        $collection = array_merge(
                            $collection,
                            $node->shallowGetElementsByTagName('tr')
                        );
                    }
                }

                if (count($tfoot)) {
                    array_merge(
                        $collection,
                        $tfoot[0]->shallowGetElementsByTagName('tr')
                    );
                }

                return $collection;

            case 'tBodies':
                return $this->shallowGetElementsByTagName('tbody');

            case 'tFoot':
                $tfoot = $this->shallowGetElementsByTagName('tfoot');

                return $tfoot[0] ?? null;

            case 'tHead':
                $thead = $this->shallowGetElementsByTagName('thead');

                return $thead[0] ?? null;

            default:
                return parent::__get($name);
        }
    }

    public function __set(string $name, $value): void
    {
        switch ($name) {
            case 'caption':
                $caption = $this->shallowGetElementsByTagName('caption');

                if (isset($caption[0])) {
                    $caption->remove();
                }

                if ($value !== null && $value instanceof HTMLTableCaptionElement) {
                    $this->insertBefore($value, $this->childNodes->first());
                }

                break;

            case 'tFoot':
                $isValid = $value === null
                    || ($value instanceof HTMLTableSectionElement && $value->tagName === 'TFOOT');

                if (!$isValid) {
                    throw new HierarchyRequestError();
                }

                $tfoot = $this->shallowGetElementsByTagName('tfoot');

                if (isset($tfoot[0])) {
                    $tFoot[0]->remove();
                }

                if ($value !== null) {
                    foreach ($this->childNodes as $node) {
                        if (
                            !$node instanceof HTMLTableCaptionElement
                            && !$node instanceof HTMLTableColElement
                            && $node->tagName === 'THEAD'
                        ) {
                            break;
                        }
                    }

                    $tfoot->insertBefore($value, $node);
                }

                break;

            case 'tHead':
                $isValid = $value === null
                    || ($value instanceof HTMLTableSectionElement && $value->tagName === 'THEAD');

                if (!$isValid) {
                    throw new HierarchyRequestError();
                }

                $thead = $this->shallowGetElementsByTagName('thead');

                if (isset($thead[0])) {
                    $thead[0]->remove();
                }

                if ($value !== null) {
                    foreach ($this->childNodes as $node) {
                        if (
                            !$node instanceof HTMLTableCaptionElement
                            && !$node instanceof HTMLTableColElement
                        ) {
                            break;
                        }
                    }

                    $thead->insertBefore($value, $node);
                }

                break;

            default:
                parent::__set($name, $value);
        }
    }

    /**
     * Returns the first caption element in the table, if one exists.
     * Otherwise, it creates a new HTMLTableCaptionElement and inserts it before
     * the table's first child and returns the newly created caption element.
     */
    public function createCaption(): HTMLTableCaptionElement
    {
        return $this->createTableChildElement('caption', $this->childNodes->first());
    }

    /**
     * Removes the first caption element in the table, if one exists.
     */
    public function deleteCaption(): void
    {
        $this->deleteTableChildElement('caption');
    }

    /**
     * Returns the first tfoot element in the table, if one exists.  Otherwise,
     * it creates a new HTMLTableSectionElement and inserts it before the first
     * element that is not a caption or colgroup element in the table and
     * returns the newly created tfoot element.
     */
    public function createTHead(): HTMLTableSectionElement
    {
        foreach ($this->childNodes as $node) {
            if (
                !$node instanceof HTMLTableCaptionElement
                && !$node instanceof HTMLTableColElement
            ) {
                break;
            }
        }

        return $this->createTableChildElement('thead', $node);
    }

    /**
     * Removes the first thead element in the table, if one exists.
     */
    public function deleteTHead(): void
    {
        $this->deleteTableChildElement('thead');
    }

    /**
     * Returns the first tfoot element in the table, if one exists.  Otherwise,
     * it creates a new HTMLTableSectionElement and inserts it before the first
     * element that is not a caption, colgroup, or thead element in the table
     * and returns the newly created tfoot element.
     */
    public function createTFoot(): HTMLTableSectionElement
    {
        foreach ($this->childNodes as $node) {
            if (
                !$node instanceof HTMLTableCaptionElement
                && !$node instanceof HTMLTableColElement
                && $node->tagName !== 'THEAD'
            ) {
                break;
            }
        }

        return $this->createTableChildElement('tfoot', $node);
    }

    /**
     * Removes the first tfoot element in the table, if one exists.
     */
    public function deleteTFoot(): void
    {
        $this->deleteTableChildElement('tfoot');
    }

    /**
     * Creates a new HTMLTableSectionElement and inserts it after the last tbody
     * element, if one exists, otherwise it is appended to the table and returns
     * the newly created tbody element.
     */
    public function createTBody(): HTMLTableSectionElement
    {
        $tbodies = $this->shallowGetElementsByTagName('tbody');
        $len = count($tbodies);
        $lastTbody = $len ? $tbodies[$len - 1]->nextSibling : null;
        $node = $this->nodeDocument->createElement('tbody');
        $this->insertBefore($node, $lastTbody);

        return $node;
    }

    /**
     * Creates a new HTMLTableRowElement (tr), and a new HTMLTableSectionElement
     * (tbody) if one does not already exist. It then inserts the newly created
     * tr element at the specified location. It returns the newly created tr
     * element.
     *
     * @param int $index (optional) A value of -1, which is the default, is equvilant to appending the new row to the
     *                   end of the table.
     *
     * @throws \Rowbot\DOM\Exception\IndexSizeError If $index is < -1 or > the number of rows in the table.
     */
    public function insertRow(int $index = -1): HTMLTableRowElement
    {
        $rows = $this->rows;
        $numRows = count($rows);

        if ($index < -1 || $index > $numRows) {
            throw new IndexSizeError();
        }

        $tr = $this->nodeDocument->createElement('tr');

        if (!$numRows) {
            $tbodies = $this->shallowGetElementsByTagName('tbody');
            $numTbodies = count($tbodies);

            if (!$tbodies) {
                $tbody = $this->nodeDocument->createElement('tbody');
                $tbody->appendChild($tr);
                $this->appendChild($tbody);
            } else {
                $tbodies[$numTbodies - 1]->appendChild($tr);
            }
        } elseif ($index === -1 || $index === $numRows) {
            $rows[$numRows - 1]->parentNode->appendChild($tr);
        } else {
            $rows[$index]->before($tr);
        }

        return $tr;
    }

    /**
     * Removes the tr element at the given position.
     *
     * @param int $index A value of -1 will remove the last tr element in the table.
     *
     * @throws \Rowbot\DOM\Exception\IndexSizeError If $index < -1 or >= the number of tr elements in the table.
     */
    public function deleteRow(int $index): void
    {
        $rows = $this->rows;
        $numRows = count($rows);

        if ($index === -1) {
            $index = $numRows - 1;
        }

        if ($index < 0 || $index >= $numRows) {
            throw new IndexSizeError();
        }

        $rows[$index]->remove();
    }

    /**
     * Checks if an element with the specified tag name exists.  If one does not
     * exist, create a new element of the specified type, and insert it before
     * the specified element.  Then return the newely created element.
     *
     * @param string $element The tag name of the element to check against.
     *
     * @param \Rowbot\DOM\Element\HTML\HTMLElement $insertBefore The element to insert against. Null will append the
     *                                                           element to the end of the table.
     */
    private function createTableChildElement(string $element, ?HTMLElement $insertBefore): HTMLElement
    {
        $nodes = $this->shallowGetElementsByTagName($element);

        if (!isset($nodes[0])) {
            $node = $this->nodeDocument->createElement($element);
            $this->insertBefore($node, $insertBefore);
        } else {
            $node = $nodes[0];
        }

        return $node;
    }

    /**
     * Removes the first specified element found, if any.
     */
    private function deleteTableChildElement(string $element): void
    {
        $node = $this->shallowGetElementsByTagName($element);

        if (isset($node[0])) {
            $node->remove();
        }
    }
}
