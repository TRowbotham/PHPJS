<?php

declare(strict_types=1);

namespace Rowbot\DOM\Parser\HTML\InsertionMode;

use Rowbot\DOM\Parser\Token\CharacterToken;
use Rowbot\DOM\Parser\Token\CommentToken;
use Rowbot\DOM\Parser\Token\DoctypeToken;
use Rowbot\DOM\Parser\Token\EndTagToken;
use Rowbot\DOM\Parser\Token\StartTagToken;
use Rowbot\DOM\Parser\Token\Token;

/**
 * @see https://html.spec.whatwg.org/multipage/syntax.html#parsing-main-inheadnoscript
 */
class InHeadNoScriptInsertionMode extends InsertionMode
{
    public function processToken(Token $token): void
    {
        if ($token instanceof DoctypeToken) {
            // Parse error.
            // Ignore the token.
            return;
        } elseif ($token instanceof StartTagToken) {
            if ($token->tagName === 'html') {
                // Process the token using the rules for the "in body" insertion
                // mode.
                (new InBodyInsertionMode($this->context))->processToken($token);

                return;
            }

            if (
                $token->tagName === 'basefont'
                || $token->tagName === 'bgsound'
                || $token->tagName === 'link'
                || $token->tagName === 'meta'
                || $token->tagName === 'noframes'
                || $token->tagName === 'style'
            ) {
                // Process the token using the rules for the "in head" insertion mode.
                (new InHeadInsertionMode($this->context))->processToken($token);

                return;
            }

            if ($token->tagName === 'head' || $token->tagName === 'noscript') {
                // Parse error.
                // Ignore the token.
                return;
            }
        } elseif ($token instanceof EndTagToken) {
            if ($token->tagName === 'noscript') {
                // Pop the current node (which will be a noscript element) from the
                // stack of open elements; the new current node will be a head
                // element.
                $this->context->parser->openElements->pop();

                // Switch the insertion mode to "in head".
                $this->context->insertionMode = new InHeadInsertionMode($this->context);

                return;
            }

            if ($token->tagName === 'br') {
                // Act as described in the "anything else" entry below.
                $this->inHeadNoScriptInsertionModeAnythingElse($token);

                return;
            }

            // Parse error.
            // Ignore the token.
            return;
        } elseif (
            (
                $token instanceof CharacterToken
                && (
                    $token->data === "\x09"
                    || $token->data === "\x0A"
                    || $token->data === "\x0C"
                    || $token->data === "\x0D"
                    || $token->data === "\x20"
                )
            )
            || $token instanceof CommentToken
        ) {
            // Process the token using the rules for the "in head" insertion
            // mode.
            (new InHeadInsertionMode($this->context))->processToken($token);

            return;
        }

        $this->inHeadNoScriptInsertionModeAnythingElse($token);
    }

    /**
     * The "in head noscript" insertion mode "anything else" steps.
     */
    private function inHeadNoScriptInsertionModeAnythingElse(Token $token): void
    {
        // Parse error.
        // Pop the current node (which will be a noscript element) from the
        // stack of open elements; the new current node will be a head
        // element.
        $this->context->parser->openElements->pop();

        // Switch the insertion mode to "in head".
        $this->context->insertionMode = new InHeadInsertionMode($this->context);

        // Reprocess the token.
        $this->context->insertionMode->processToken($token);
    }
}
