<?php

declare(strict_types=1);

namespace Rowbot\DOM\Element\HTML;

use Closure;
use Generator;
use Rowbot\DOM\Element\Element;
use Rowbot\DOM\Element\ElementFactory;
use Rowbot\DOM\Exception\HierarchyRequestError;
use Rowbot\DOM\Exception\IndexSizeError;
use Rowbot\DOM\Exception\TypeError;
use Rowbot\DOM\HTMLCollection;
use Rowbot\DOM\Namespaces;

use function strtolower;

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
 * @property-read \Rowbot\DOM\HTMLCollection<\Rowbot\DOM\Element\HTML\HTMLTableRowElement>     $rows    Returns a list of all the <tr>
 *                                                                                elements, in order, that are in the
 *                                                                                table.
 * @property-read \Rowbot\DOM\HTMLCollection<\Rowbot\DOM\Element\HTML\HTMLTableSectionElement> $tBodies Returns a list of all the <tbody>
 *                                                                                elements, in order, that are in the
 *                                                                                table.
 */
class HTMLTableElement extends HTMLElement
{
    /**
     * @var \Rowbot\DOM\HTMLCollection<\Rowbot\DOM\Element\HTML\HTMLTableRowElement>|null
     */
    private $rowsCollection;

    /**
     * @var \Rowbot\DOM\HTMLCollection<\Rowbot\DOM\Element\HTML\HTMLTableSectionElement>|null
     */
    private $tBodyCollection;

    public function __get(string $name)
    {
        switch ($name) {
            case 'caption':
                // The caption IDL attribute must return, on getting, the first caption element
                // child of the table element, if any, or null otherwise.
                $node = $this->childNodes->first();

                while ($node) {
                    if ($node instanceof HTMLTableCaptionElement) {
                        return $node;
                    }

                    $node = $node->nextSibling;
                }

                return null;

            case 'rows':
                if ($this->rowsCollection === null) {
                    $this->rowsCollection = new HTMLCollection($this, $this->getRowsFilter());
                }

                return $this->rowsCollection;

            case 'tBodies':
                if ($this->tBodyCollection === null) {
                    $this->tBodyCollection = new HTMLCollection(
                        $this,
                        static function (self $root) {
                            $node = $root->firstChild;

                            while ($node !== null) {
                                if (
                                    $node instanceof HTMLTableSectionElement
                                    && $node->localName === 'tbody'
                                ) {
                                    yield $node;
                                }

                                $node = $node->nextSibling;
                            }
                        }
                    );
                }

                return $this->tBodyCollection;

            case 'tFoot':
            case 'tHead':
                $name = strtolower($name);

                // The tHead IDL attribute must return, on getting, the first thead element child of
                // the table element, if any, or null otherwise.
                $node = $this->childNodes->first();

                while ($node) {
                    if ($node instanceof HTMLTableSectionElement && $node->localName === $name) {
                        return $node;
                    }

                    $node = $node->nextSibling;
                }

                return null;

            default:
                return parent::__get($name);
        }
    }

    public function __set(string $name, $value): void
    {
        switch ($name) {
            case 'caption':
                // On setting, the first caption element child of the table element, if any, must be
                // removed, and the new value, if not null, must be inserted as the first node of
                // the table element.
                if ($value !== null && !$value instanceof HTMLTableCaptionElement) {
                    throw new TypeError();
                }

                $node = $this->childNodes->first();
                $caption = null;

                while ($node) {
                    if ($node instanceof HTMLTableCaptionElement) {
                        $caption = $node;

                        break;
                    }

                    $node = $node->nextSibling;
                }

                if ($caption) {
                    $caption->removeNode();
                }

                if ($value) {
                    $this->preinsertNode($value, $this->childNodes->first());
                }

                break;

            case 'tFoot':
                if ($value !== null && !$value instanceof HTMLTableSectionElement) {
                    throw new TypeError();
                }

                // If the new value is neither null nor a tfoot element, then a
                // "HierarchyRequestError" DOMException must be thrown instead.
                if ($value && $value->localName !== 'tfoot') {
                    throw new HierarchyRequestError();
                }

                // On setting, if the new value is null or a tfoot element, the first tfoot element
                // child of the table element, if any, must be removed,
                $node = $this->childNodes->first();

                while ($node) {
                    if ($node instanceof HTMLTableSectionElement && $node->localName === 'tfoot') {
                        $node->removeNode();

                        break;
                    }

                    $node = $node->nextSibling;
                }

                // and the new value, if not null, must be inserted at the end of the table.
                if (!$value) {
                    return;
                }

                $this->preinsertNode($value);

                break;

            case 'tHead':
                if ($value !== null && !$value instanceof HTMLTableSectionElement) {
                    throw new TypeError();
                }

                // If the new value is neither null nor a thead element, then a
                // "HierarchyRequestError" DOMException must be thrown instead.
                if ($value && $value->localName !== 'thead') {
                    throw new HierarchyRequestError();
                }

                // On setting, if the new value is null or a thead element, the first thead element
                // child of the table element, if any, must be removed,
                $node = $this->childNodes->first();

                while ($node) {
                    if ($node instanceof HTMLTableSectionElement && $node->localName === 'thead') {
                        $node->removeNode();

                        break;
                    }

                    $node = $node->nextSibling;
                }

                // and the new value, if not null, must be inserted immediately before the first
                // element in the table element that is neither a caption element nor a colgroup
                // element, if any,
                if (!$value) {
                    return;
                }

                $node = $this->childNodes->first();

                while ($node) {
                    if (
                        $node instanceof Element
                        && !$node instanceof HTMLTableColElement
                        && !$node instanceof HTMLTableCaptionElement
                    ) {
                        $this->preinsertNode($value, $node);

                        return;
                    }

                    $node = $node->nextSibling;
                }

                // or at the end of the table if there are no such elements.
                $this->preinsertNode($value);

                break;

            default:
                parent::__set($name, $value);
        }
    }

    /**
     * Returns the first caption element in the table, if one exists.
     * Otherwise, it creates a new HTMLTableCaptionElement and inserts it before
     * the table's first child and returns the newly created caption element.
     *
     * @see https://html.spec.whatwg.org/multipage/tables.html#dom-table-createcaption
     */
    public function createCaption(): HTMLTableCaptionElement
    {
        $firstChild = $this->childNodes->first();
        $node = $firstChild;

        while ($node) {
            if ($node instanceof HTMLTableCaptionElement) {
                return $node;
            }

            $node = $node->nextSibling;
        }

        $caption = ElementFactory::create($this->nodeDocument, 'caption', Namespaces::HTML);
        $this->insertNode($caption, $firstChild);

        return $caption;
    }

    /**
     * Removes the first caption element in the table, if one exists.
     *
     * @see https://html.spec.whatwg.org/multipage/tables.html#dom-table-deletecaption
     */
    public function deleteCaption(): void
    {
        $node = $this->childNodes->first();

        while ($node) {
            if ($node instanceof HTMLTableCaptionElement) {
                $node->removeNode();

                return;
            }

            $node = $node->nextSibling;
        }
    }

    /**
     * Returns the first tfoot element in the table, if one exists.  Otherwise,
     * it creates a new HTMLTableSectionElement and inserts it before the first
     * element that is not a caption or colgroup element in the table and
     * returns the newly created tfoot element.
     *
     * @see https://html.spec.whatwg.org/multipage/tables.html#dom-table-createthead
     */
    public function createTHead(): HTMLTableSectionElement
    {
        $firstChild = $this->childNodes->first();
        $node = $firstChild;

        while ($node) {
            if ($node instanceof HTMLTableSectionElement && $node->localName === 'thead') {
                return $node;
            }

            $node = $node->nextSibling;
        }

        $thead = ElementFactory::create($this->nodeDocument, 'thead', Namespaces::HTML);
        $node = $firstChild;

        while ($node) {
            if (
                $node instanceof Element
                && !$node instanceof HTMLTableColElement
                && !$node instanceof HTMLTableCaptionElement
            ) {
                $this->preinsertNode($thead, $node);

                return $thead;
            }

            $node = $node->nextSibling;
        }

        $this->preinsertNode($thead);

        return $thead;
    }

    /**
     * Removes the first thead element in the table, if one exists.
     *
     * @see https://html.spec.whatwg.org/multipage/tables.html#dom-table-deletethead
     */
    public function deleteTHead(): void
    {
        $node = $this->childNodes->first();

        while ($node) {
            if ($node instanceof HTMLTableSectionElement && $node->localName === 'thead') {
                $node->removeNode();

                return;
            }

            $node = $node->nextSibling;
        }
    }

    /**
     * Returns the first tfoot element child of the table element, if any; otherwise a new tfoot
     * element must be table-created and inserted at the end of the table, and then that new
     * element must be returned.
     *
     * @see https://html.spec.whatwg.org/multipage/tables.html#dom-table-createtfoot
     */
    public function createTFoot(): HTMLTableSectionElement
    {
        $node = $this->childNodes->first();

        while ($node) {
            if ($node instanceof HTMLTableSectionElement && $node->localName === 'tfoot') {
                return $node;
            }

            $node = $node->nextSibling;
        }

        $tfoot = ElementFactory::create($this->nodeDocument, 'tfoot', Namespaces::HTML);
        $this->insertNode($tfoot, null);

        return $tfoot;
    }

    /**
     * Removes the first tfoot element in the table, if one exists.
     *
     * @see https://html.spec.whatwg.org/multipage/tables.html#dom-table-deletetfoot
     */
    public function deleteTFoot(): void
    {
        $node = $this->childNodes->first();

        while ($node) {
            if ($node instanceof HTMLTableSectionElement && $node->localName === 'tfoot') {
                $node->removeNode();

                return;
            }

            $node = $node->nextSibling;
        }
    }

    /**
     * Creates a new HTMLTableSectionElement and inserts it after the last tbody
     * element, if one exists, otherwise it is appended to the table and returns
     * the newly created tbody element.
     *
     * @see https://html.spec.whatwg.org/multipage/tables.html#dom-table-createtbody
     */
    public function createTBody(): HTMLTableSectionElement
    {
        $node = $this->childNodes->last();
        $lastTbody = null;

        while ($node) {
            if ($node instanceof HTMLTableSectionElement && $node->localName === 'tbody') {
                $lastTbody = $node;

                break;
            }

            $node = $node->previousSibling;
        }

        $tbody = ElementFactory::create($this->nodeDocument, 'tbody', Namespaces::HTML);
        $child = null;

        if ($lastTbody) {
            $child = $lastTbody->nextSibling;
        }

        $this->insertNode($tbody, $child);

        return $tbody;
    }

    /**
     * Creates a new HTMLTableRowElement (tr), and a new HTMLTableSectionElement
     * (tbody) if one does not already exist. It then inserts the newly created
     * tr element at the specified location. It returns the newly created tr
     * element.
     *
     * @see https://html.spec.whatwg.org/multipage/tables.html#dom-table-insertrow
     *
     * @throws \Rowbot\DOM\Exception\IndexSizeError If $index is < -1 or > the number of rows in the table.
     */
    public function insertRow(int $index = -1): HTMLTableRowElement
    {
        // If index is less than −1 or greater than the number of elements in rows collection:
        //    The method must throw an "IndexSizeError" DOMException.
        if ($index < -1) {
            throw new IndexSizeError();
        }

        $rows = $this->getRowsFilter()($this);
        $indexedRow = null;
        $lastRow = null;
        $numRows = 0;

        while ($rows->valid()) {
            $lastRow = $rows->current();

            if ($index === $numRows) {
                $indexedRow = $lastRow;
            }

            ++$numRows;
            $rows->next();
        }

        if ($index > $numRows) {
            throw new IndexSizeError();
        }

        $tableRow = ElementFactory::create($this->nodeDocument, 'tr', Namespaces::HTML);
        $node = $this->childNodes->last();
        $lastTbody = null;

        while ($node) {
            if ($node instanceof HTMLTableSectionElement && $node->localName === 'tbody') {
                $lastTbody = $node;
            }

            $node = $node->previousSibling;
        }

        // If the rows collection has zero elements in it, and the table has no tbody elements in it:
        if ($numRows === 0 && $lastTbody === null) {
            // The method must table-create a tbody element, then table-create a tr element, then
            // append the tr element to the tbody element, then append the tbody element to the
            // table element, and finally return the tr element.
            $tableBody = ElementFactory::create($this->nodeDocument, 'tbody', Namespaces::HTML);
            $tableBody->insertNode($tableRow, null);
            $this->insertNode($tableBody, null);

            return $tableRow;
        }

        // If the rows collection has zero elements in it:
        if ($numRows === 0) {
            // The method must table-create a tr element, append it to the last tbody element in the
            // table, and return the tr element.
            $lastTbody->insertNode($tableRow, null);

            return $tableRow;
        }

        // If index is −1 or equal to the number of items in rows collection:
        if ($index === -1 || $index === $numRows) {
            // The method must table-create a tr element, and append it to the parent of the last tr
            // element in the rows collection. Then, the newly created tr element must be returned.
            $lastRow->parentNode->insertNode($tableRow, null);

            return $tableRow;
        }

        // Otherwise:
        // The method must table-create a tr element, insert it immediately before the indexth tr element in the rows
        // collection, in the same parent, and finally must return the newly created tr element.
        $indexedRow->parentNode->insertNode($tableRow, $indexedRow);

        return $tableRow;
    }

    /**
     * Removes the tr element at the given position.
     *
     * @see https://html.spec.whatwg.org/multipage/tables.html#dom-table-deleterow
     *
     * @throws \Rowbot\DOM\Exception\IndexSizeError If $index < -1 or >= the number of tr elements in the table.
     */
    public function deleteRow(int $index): void
    {
        // 1. If index is less than −1 or greater than or equal to the number of elements in the
        // rows collection, then throw an "IndexSizeError" DOMException.
        if ($index < -1) {
            throw new IndexSizeError();
        }

        $numRows = 0;
        $indexedRow = null;
        $lastRow = null;
        $rows = $this->getRowsFilter()($this);
        $rows->rewind();

        while ($rows->valid()) {
            $lastRow = $rows->current();

            if ($numRows === $index) {
                $indexedRow = $lastRow;
            }

            ++$numRows;
            $rows->next();
        }

        if ($index >= $numRows) {
            throw new IndexSizeError();
        }

        // 2. If index is −1, then remove the last element in the rows collection from its parent,
        // or do nothing if the rows collection is empty.
        if ($lastRow === null) {
            return;
        }

        if ($index === -1) {
            $lastRow->removeNode();

            return;
        }

        // 3. Otherwise, remove the indexth element in the rows collection from its parent.
        $indexedRow->removeNode();
    }

    private function getRowsFilter(): Closure
    {
        return static function (self $root): Generator {
            $node = $root->firstChild;
            $bodyOrRow = [];
            $footers = [];

            while ($node) {
                if ($node instanceof HTMLTableSectionElement) {
                    $name = $node->localName;

                    if ($name === 'tbody') {
                        // Save the section for later, in order.
                        $bodyOrRow[] = $node;
                    } elseif ($name === 'tfoot') {
                        $footers[] = $node;
                    } elseif ($name === 'thead') {
                        // We're in a thead, so we can emit the rows as we find them.
                        $child = $node->firstChild;

                        while ($child) {
                            if ($child instanceof HTMLTableRowElement) {
                                yield $child;
                            }

                            $child = $child->nextSibling;
                        }
                    }
                } elseif ($node instanceof HTMLTableRowElement) {
                    $bodyOrRow[] = $node;
                }

                $node = $node->nextSibling;
            }

            foreach ($bodyOrRow as $potenialRow) {
                if ($potenialRow instanceof HTMLTableRowElement) {
                    yield $potenialRow;

                    continue;
                }

                $node = $potenialRow->firstChild;

                while ($node) {
                    if ($node instanceof HTMLTableRowElement) {
                        yield $node;
                    }

                    $node = $node->nextSibling;
                }
            }

            foreach ($footers as $footer) {
                $node = $footer->firstChild;

                while ($node) {
                    if ($node instanceof HTMLTableRowElement) {
                        yield $node;
                    }

                    $node = $node->nextSibling;
                }
            }
        };
    }
}
