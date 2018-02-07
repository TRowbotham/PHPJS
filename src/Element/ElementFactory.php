<?php
namespace Rowbot\DOM\Element;

use Rowbot\DOM\Namespaces;
use Rowbot\DOM\Exception\DOMException;

abstract class ElementFactory
{
    private const HTML_ELEMENTS = [
        'a'          => 'HTMLAnchorElement',
        'abbr'       => 'HTMLElement',
        'acronym'    => 'HTMLElement', // Obsolete
        'address'    => 'HTMLElement',
        'area'       => 'HTMLAreaElement',
        'article'    => 'HTMLElement',
        'aside'      => 'HTMLElement',
        'audio'      => 'HTMLAudioElement',
        'b'          => 'HTMLElement',
        'base'       => 'HTMLBaseElement',
        'basefont'   => 'HTMLElement', // Obsolete
        'bdi'        => 'HTMLElement',
        'bdo'        => 'HTMLElement',
        'big'        => 'HTMLElement', // Obsolete
        'blockquote' => 'HTMLQuoteElement',
        'body'       => 'HTMLBodyElement',
        'br'         => 'HTMLBRElement',
        'button'     => 'HTMLButtonElement',
        'canvas'     => 'HTMLCanvasElement',
        'caption'    => 'HTMLTableCaptionElement',
        'center'     => 'HTMLElement', // Obsolete
        'cite'       => 'HTMLElement',
        'code'       => 'HTMLElement',
        'col'        => 'HTMLTableColElement',
        'colgroup'   => 'HTMLTableColElement',
        'data'       => 'HTMLDataElement',
        'datalist'   => 'HTMLDataListElement',
        'dd'         => 'HTMLElement',
        'del'        => 'HTMLModElement',
        'details'    => 'HTMLDetailsElement',
        'dfn'        => 'HTMLElement',
        'dialog'     => 'HTMLDialogElement',
        'dir'        => 'HTMLDirectoryElement', // Obsolete
        'div'        => 'HTMLDivElement',
        'dl'         => 'HTMLDListElement',
        'dt'         => 'HTMLElement',
        'em'         => 'HTMLElement',
        'embed'      => 'HTMLEmbedElement',
        'fieldset'   => 'HTMLFieldSetElement',
        'figcaption' => 'HTMLElement',
        'figure'     => 'HTMLElement',
        'font'       => 'HTMLFontElement', // Obsolete
        'footer'     => 'HTMLElement',
        'form'       => 'HTMLFormElement',
        'frame'      => 'HTMLFrameElement', // Deprecated
        'frameset'   => 'HTMLFrameSetElement', // Deprecated
        'h1'         => 'HTMLHeadingElement',
        'h2'         => 'HTMLHeadingElement',
        'h3'         => 'HTMLHeadingElement',
        'h4'         => 'HTMLHeadingElement',
        'h5'         => 'HTMLHeadingElement',
        'h6'         => 'HTMLHeadingElement',
        'head'       => 'HTMLHeadElement',
        'header'     => 'HTMLElement',
        'hgroup'     => 'HTMLElement', // Obsolete
        'hr'         => 'HTMLHRElement',
        'html'       => 'HTMLHtmlElement',
        'i'          => 'HTMLElement',
        'iframe'     => 'HTMLIFrameElement',
        'img'        => 'HTMLImageElement',
        'input'      => 'HTMLInputElement',
        'ins'        => 'HTMLModElement',
        'kbd'        => 'HTMLElement',
        'label'      => 'HTMLLabelElement',
        'legend'     => 'HTMLLegendElement',
        'li'         => 'HTMLLIElement',
        'link'       => 'HTMLLinkElement',
        'main'       => 'HTMLElement',
        'map'        => 'HTMLMapElement',
        'mark'       => 'HTMLElement',
        'marquee'    => 'HTMLMarqueeElement', // Obsolete
        'menu'       => 'HTMLMenuElement',
        'menuitem'   => 'HTMLMenuItemElement',
        'meta'       => 'HTMLMetaElement',
        'meter'      => 'HTMLMeterElement',
        'nav'        => 'HTMLElement',
        'noembed'    => 'HTMLElement', // Deprecated and non-standard
        'noframes'   => 'HTMLElement',
        'noscript'   => 'HTMLElement',
        'object'     => 'HTMLObjectElement',
        'ol'         => 'HTMLOListElement',
        'optgroup'   => 'HTMLOptGroupElement',
        'option'     => 'HTMLOptionElement',
        'output'     => 'HTMLOutputElement',
        'p'          => 'HTMLParagraphElement',
        'param'      => 'HTMLParamElement',
        'picture'    => 'HTMLPictureElement',
        'pre'        => 'HTMLPreElement',
        'progress'   => 'HTMLProgressElement',
        'q'          => 'HTMLQuoteElement',
        'rp'         => 'HTMLElement',
        'rt'         => 'HTMLElement',
        'rtc'        => 'HTMLElement',
        'ruby'       => 'HTMLElement',
        's'          => 'HTMLElement',
        'samp'       => 'HTMLElement',
        'script'     => 'HTMLScriptElement',
        'section'    => 'HTMLElement',
        'select'     => 'HTMLSelectElement',
        'small'      => 'HTMLElement',
        'source'     => 'HTMLSourceElement',
        'span'       => 'HTMLSpanElement',
        'strike'     => 'HTMLElement', // Obsolete
        'strong'     => 'HTMLElement',
        'style'      => 'HTMLStyleElement',
        'sub'        => 'HTMLElement',
        'summary'    => 'HTMLElement',
        'sup'        => 'HTMLElement',
        'table'      => 'HTMLTableElement',
        'tbody'      => 'HTMLTableSectionElement',
        'td'         => 'HTMLTableDataCellElement',
        'template'   => 'HTMLTemplateElement',
        'textarea'   => 'HTMLTextAreaElement',
        'tfoot'      => 'HTMLTableSectionElement',
        'th'         => 'HTMLTableHeaderCellElement',
        'thead'      => 'HTMLTableSectionElement',
        'time'       => 'HTMLTimeElement',
        'title'      => 'HTMLTitleElement',
        'tr'         => 'HTMLTableRowElement',
        'track'      => 'HTMLTrackElement',
        'tt'         => 'HTMLElement', // Obsolete
        'u'          => 'HTMLElement',
        'ul'         => 'HTMLUListElement',
        'var'        => 'HTMLElement',
        'video'      => 'HTMLVideoElement',
        'wbr'        => 'HTMLElement',
        'xmp'        => 'HTMLElement' // Obsolete
    ];

    /**
     * @see https://dom.spec.whatwg.org/#concept-create-element
     *
     * @param Document $document The element's owner document.
     *
     * @param string $localName The element's local name that you are creating.
     *
     * @param string $namespace The namespace that the element belongs to.
     *
     * @param string|null $prefix Optional. The namespace prefix of the
     *     element.
     *
     * @return Element
     */
    public static function create(
        $document,
        $localName,
        $namespace,
        $prefix = null
    ) {
        $interface = 'Element';

        if ($namespace === Namespaces::HTML) {
            $interface = 'HTML\\HTMLUnknownElement';

            if (isset(self::HTML_ELEMENTS[$localName])) {
                $interface = 'HTML\\' . self::HTML_ELEMENTS[$localName];
            }
        }

        return ('\\Rowbot\\DOM\\Element\\' . $interface)::create(
            $document,
            $localName,
            $namespace,
            $prefix
        );
    }

    /**
     * @see https://dom.spec.whatwg.org/#internal-createelementns-steps
     *
     * @param Document $document The Element's owner document.
     *
     * @param string $namespace The Element's namespace.
     *
     * @param string $qualifiedName The Element's fully qualified name.
     *
     * @return Element
     */
    public static function createNS($document, $namespace, $qualifiedName)
    {
        try {
            list(
                $namespace,
                $prefix,
                $localName
            ) = Namespaces::validateAndExtract(
                $namespace,
                $qualifiedName
            );
        } catch (DOMException $e) {
            throw $e;
        }

        try {
            $element = self::create(
                $document,
                $localName,
                $namespace,
                $prefix
            );
        } catch (DOMException $e) {
            throw $e;
        }

        return $element;
    }
}