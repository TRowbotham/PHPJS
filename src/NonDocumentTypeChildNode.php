<?php

declare(strict_types=1);

namespace Rowbot\DOM;

use Rowbot\DOM\Element\Element;

/**
 * @see https://dom.spec.whatwg.org/#interface-nondocumenttypechildnode
 */
trait NonDocumentTypeChildNode
{
    /**
     * Gets the next element sibling.
     *
     * @see https://dom.spec.whatwg.org/#dom-nondocumenttypechildnode-previouselementsibling
     */
    private function getNextElementSibling(): ?Element
    {
        $node = $this->nextSibling;

        while ($node) {
            if ($node instanceof Element) {
                break;
            }

            $node = $node->nextSibling;
        }

        return $node;
    }

    /**
     * Gets the previous element sibling.
     *
     * @see https://dom.spec.whatwg.org/#dom-nondocumenttypechildnode-nextelementsibling
     */
    private function getPreviousElementSibling(): ?Element
    {
        $node = $this->previousSibling;

        while ($node) {
            if ($node instanceof Element) {
                break;
            }

            $node = $node->previousSibling;
        }

        return $node;
    }
}
