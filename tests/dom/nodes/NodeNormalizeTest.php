<?php
namespace Rowbot\DOM\Tests\dom\nodes;

use Rowbot\DOM\Tests\dom\DocumentGetter;
use Rowbot\DOM\Tests\TestCase;

/**
 * @see https://github.com/w3c/web-platform-tests/blob/master/dom/nodes/Node-normalize.html
 */
class NodeNormalizeTest extends TestCase
{
    use DocumentGetter;

    public function test1()
    {
        $document = $this->getHTMLDocument();
        $df = $document->createDocumentFragment();
        $t1 = $document->createTextNode("1");
        $t2 = $document->createTextNode("2");
        $t3 = $document->createTextNode("3");
        $t4 = $document->createTextNode("4");

        $df->appendChild($t1);
        $df->appendChild($t2);
        $this->assertCount(2, $df->childNodes);
        $this->assertEquals('12', $df->textContent);

        $e1 = $document->createElement('x');
        $df->appendChild($e1);
        $e1->appendChild($t3);
        $e1->appendChild($t4);
        $document->normalize();
        $this->assertCount(2, $e1->childNodes);
        $this->assertEquals('34', $e1->textContent);
        $this->assertCount(3, $df->childNodes);
        $this->assertEquals('1', $t1->data);
        $df->normalize();
        $this->assertCount(2, $df->childNodes);
        $this->assertSame($t1, $df->firstChild);
        $this->assertEquals('12', $t1->data);
        $this->assertEquals('2', $t2->data);
        $this->assertSame($t3, $e1->firstChild);
        $this->assertEquals('34', $t3->data);
        $this->assertEquals('4', $t4->data);
    }

    /**
     * Empty text nodes separated by a non-empty text node.
     */
    public function test2()
    {
        $document = $this->getHTMLDocument();
        $div = $document->createElement('div');
        $t1 = $div->appendChild($document->createTextNode(''));
        $t2 = $div->appendChild($document->createTextNode('a'));
        $t3 = $div->appendChild($document->createTextNode(''));
        $this->assertSame([$t1, $t2, $t3], iterator_to_array($div->childNodes));
        $div->normalize();
        $this->assertSame([$t2], iterator_to_array($div->childNodes));
    }

    /**
     * Empty text nodes.
     */
    public function test3()
    {
        $document = $this->getHTMLDocument();
        $div = $document->createElement('div');
        $t1 = $div->appendChild($document->createTextNode(''));
        $t2 = $div->appendChild($document->createTextNode(''));
        $this->assertSame([$t1, $t2], iterator_to_array($div->childNodes));
        $div->normalize();
        $this->assertSame([], iterator_to_array($div->childNodes));
    }
}
