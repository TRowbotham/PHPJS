<?php
// https://developer.mozilla.org/en-US/docs/Web/API/ParentNode
// https://dom.spec.whatwg.org/#interface-parentnode

trait ParentNode {
	private $mChildren = array();
	private $mChildElementCount = 0;
	private $mInvalidateChildren = true;

	/**
	 * Inserts nodes after the lastChild of this node, while replacing strings in nodes
	 * with equvilant Text nodes.
	 * @param Node|DOMString ...$aNodes One or more Nodes or strings to be appended to this Node.
	 */
	public function append() {
		$node = $this->mutationMethodMacro(func_get_args());
		$this->preinsertNodeBeforeChild($node, null);
	}

	private function filterChildElements($aNode) {
		return $aNode->nodeType == Node::ELEMENT_NODE;
	}

	private function getChildren() {
		$this->maybeInvalidateChildren();

		return $this->mChildElementCount ? $this->mChildren : null;
	}

	private function getFirstElementChild() {
		$this->maybeInvalidateChildren();

		return $this->mChildElementCount ? $this->mChildren[0] : null;
	}

	private function getLastElementChild() {
		$this->maybeInvalidateChildren();

		return $this->mChildElementCount ? $this->mChildren[$this->mChildElementCount - 1] : null;
	}

	private function getChildElementCount() {
		$this->maybeInvalidateChildren();

		return $this->mChildElementCount ? $this->mChildElementCount : null;
	}

	private function maybeInvalidateChildren() {
		if ($this->mInvalidateChildren) {
			$this->mInvalidateChildren = false;
			$this->mChildren = array_filter($this->mChildNodes, array($this, 'filterChildElements'));
			$this->mChildElementCount = count($this->mChildren);
		}
	}
}
