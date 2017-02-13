<?php
namespace phpjs\parser\html;

use phpjs\encoding\EncodingUtils;
use phpjs\Namespaces;
use phpjs\parser\tokens\AttributeToken;
use phpjs\parser\tokens\CharacterToken;
use phpjs\parser\tokens\CommentToken;
use phpjs\parser\tokens\DoctypeToken;
use phpjs\parser\tokens\EndTagToken;
use phpjs\parser\tokens\EOFToken;
use phpjs\parser\tokens\StartTagToken;
use phpjs\parser\tokens\Token;
use phpjs\support\CodePointStream;

class Tokenizer
{
    const CHARACTER_REFERENCE_MAP = [
        0x00 => 0xFFFD,
        0x80 => 0x20AC,
        0x82 => 0x201A,
        0x83 => 0x0192,
        0x84 => 0x201E,
        0x85 => 0x2026,
        0x86 => 0x2020,
        0x87 => 0x2021,
        0x88 => 0x02C6,
        0x89 => 0x2030,
        0x8A => 0x0160,
        0x8B => 0x2039,
        0x8C => 0x0152,
        0x8E => 0x017D,
        0x91 => 0x2018,
        0x92 => 0x2019,
        0x93 => 0x201C,
        0x94 => 0x201D,
        0x95 => 0x2022,
        0x96 => 0x2013,
        0x97 => 0x2014,
        0x98 => 0x02DC,
        0x99 => 0x2122,
        0x9A => 0x0161,
        0x9B => 0x203A,
        0x9C => 0x0153,
        0x9E => 0x017E,
        0x9F => 0x0178
    ];

    // Currently there are 25 unique string lengths in entities.json
    const UNIQUE_ENTITY_LENGTHS = [
        3,
        4,
        5,
        6,
        7,
        8,
        9,
        10,
        11,
        12,
        13,
        14,
        15,
        16,
        17,
        18,
        19,
        20,
        21,
        22,
        23,
        24,
        25,
        26,
        33
    ];
    const ENTITIY_STRING_LENGTH_COUNT = 25;
    const ENTITY_PATH = __DIR__ . DIRECTORY_SEPARATOR . '..' .
        DIRECTORY_SEPARATOR . 'entities.json';
    const REPLACEMENT_CHAR = "\xEF\xBF\xBD"; // U+FFFD

    private $lastEmittedStartTagToken;
    private $inputStream;
    private $parser;
    private static $entities;
    private $treeBuilder;

    public function __construct($aInputStream, $aParser)
    {
        $this->lastEmittedStartTagToken = null;
        $this->inputStream = $aInputStream;
        $this->parser = $aParser;
        $this->treeBuilder = $aParser->getTreeBuilder();
    }

    public function run()
    {
        $returnState = null;
        $characterReferenceCode = 0;
        $attributeToken = null;
        $commentToken = null;
        $doctypeToken = null;
        $tagToken = null;
        $buffer = '';

        do {
            // Before each step of the tokenizer, the user agent must first
            // check the parser pause flag. If it is true, then the tokenizer
            // must abort the processing of any nested invocations of the
            // tokenizer, yielding control back to the caller.
            if ($this->parser->isPaused()) {
                return;
            }

            switch ($this->parser->getTokenizerState()) {
                // https://html.spec.whatwg.org/multipage/syntax.html#data-state
                case TokenizerState::DATA:
                    $c = $this->inputStream->get();

                    if ($c === '&') {
                        // Set the return state to the data state. Switch to the
                        // character reference state.
                        $returnState = TokenizerState::DATA;
                        $this->parser->setTokenizerState(
                            TokenizerState::CHARACTER_REFERENCE
                        );
                    } elseif ($c === '<') {
                        // Switch to the tag open state.
                        $this->parser->setTokenizerState(
                            TokenizerState::TAG_OPEN
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Emit the current input character as a character
                        // token.
                        yield new CharacterToken($c);
                    } elseif ($this->inputStream->isEoS()) {
                        // Emit an end-of-file token.
                        yield new EOFToken();
                    } else {
                        // Emit the current input character as a character
                        // token.
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#rcdata-state
                case TokenizerState::RCDATA:
                    $c = $this->inputStream->get();

                    if ($c === '&') {
                        // Set the return state to the RCDATA state. Switch to
                        // the character reference state.
                        $returnState = TokenizerState::RCDATA;
                        $this->parser->setTokenizerState(
                            TokenizerState::CHARACTER_REFERENCE
                        );
                    } elseif ($c === '<') {
                        // Switch to the RCDATA less-than sign state.
                        $this->parser->setTokenizerState(
                            TokenizerState::RCDATA_LESS_THAN_SIGN
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Emit a U+FFFD REPLACEMENT CHARACTER character token.
                        yield new CharacterToken(self::REPLACEMENT_CHAR);
                    } elseif ($c === $this->inputStream->isEoS()) {
                        // Emit an end-of-file token.
                        yield new EOFToken();
                    } else {
                        // Emit the current input character as a character
                        // token.
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#rawtext-state
                case TokenizerState::RAWTEXT:
                    $c = $this->inputStream->get();

                    if ($c === '<') {
                        // Switch to the RAWTEXT less-than sign state.
                        $this->parser->setTokenizerState(
                            TokenizerState::RAWTEXT_LESS_THAN_SIGN
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Emit a U+FFFD REPLACEMENT CHARACTER character token.
                        yield new CharacterToken(self::REPLACEMENT_CHAR);
                    } elseif ($this->inputStream->isEoS()) {
                        // Emit an end-of-file token.
                        yield new EOFToken();
                    } else {
                        // Emit the current input character as a character
                        // token.
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-state
                case TokenizerState::SCRIPT_DATA:
                    $c = $this->inputStream->get();

                    if ($c === '<') {
                        // Switch to the script data less-than sign state.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_LESS_THAN_SIGN
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Emit a U+FFFD REPLACEMENT CHARACTER character token.
                        yield new CharacterToken(self::REPLACEMENT_CHAR);
                    } elseif ($this->inputStream->isEoS()) {
                        // Emit an end-of-file token.
                        yield new EOFToken();
                    } else {
                        // Emit the current input character as a character
                        // token.
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#plaintext-state
                case TokenizerState::SCRIPT_DATA:
                    $c = $this->inputStream->get();

                    if ($c === "\0") {
                        // Parse error.
                        // Emit a U+FFFD REPLACEMENT CHARACTER character token.
                        yield new CharacterToken(self::REPLACEMENT_CHAR);
                    } elseif ($this->inputStream->isEoS()) {
                        // Emit an end-of-file token.
                        yield new EOFToken();
                    } else {
                        // Emit the current input character as a character token.
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#tag-open-state
                case TokenizerState::TAG_OPEN:
                    $c = $this->inputStream->get();

                    if ($c === '!') {
                        // Switch to the markup declaration open state.
                        $this->parser->setTokenizerState(
                            TokenizerState::MARKUP_DECLARATION_OPEN
                        );
                    } elseif ($c === '/') {
                        // Switch to the end tag open state.
                        $this->parser->setTokenizerState(
                            TokenizerState::END_TAG_OPEN
                        );
                    } elseif (ctype_alpha($c)) {
                        // Create a new start tag token, set its tag name to the
                        // empty string. Reconsume in the tag name state.
                        $tagToken = new StartTagToken('');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::TAG_NAME
                        );
                    } elseif ($c === '?') {
                        // Parse error.
                        // Create a comment token whose data is the empty
                        // string. Reconsume in the bogus comment state.
                        $commentToken = new CommentToken('');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::BOGUS_COMMENT
                        );
                    } else {
                        // Parse error.
                        // Emit a U+003C LESS-THAN SIGN character token.
                        // Reconsume in the data state.
                        yield new CharacterToken('<');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#end-tag-open-state
                case TokenizerState::END_TAG_OPEN:
                    $c = $this->inputStream->get();

                    if (ctype_alpha($c)) {
                        // Create a new end tag token, set its tag name to the
                        // empty string. Reconsume in the tag name state.
                        $tagToken = new EndTagToken('');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::TAG_NAME
                        );
                    } elseif ($c === '>') {
                        // Parse error.
                        // Switch to the data state.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Emit a U+003C LESS-THAN SIGN character token and a
                        // U+002F SOLIDUS character token. Reconsume in the
                        // data state.
                        yield new CharacterToken('<');
                        yield new CharacterToken('/');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::DATA
                        );
                    } else {
                        // Parse error.
                        // Create a comment token whose data is the empty
                        // string. Reconsume in the bogus comment state.
                        $commentToken = new CommentToken('');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::BOGUS_COMMENT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#tag-name-state
                case TokenizerState::TAG_NAME:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Switch to the before attribute name state.
                        $this->parser->setTokenizerState(
                            TokenizerState::BEFORE_ATTRIBUTE_NAME
                        );
                    } elseif ($c === '/') {
                        // Switch to the self-closing start tag state.
                        $this->parser->setTokenizerState(
                            TokenizerState::SELF_CLOSING_START_TAG
                        );
                    } elseif ($c === '>') {
                        // Switch to the data state. Emit the current tag token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $tagToken;
                    } elseif (ctype_upper($c)) {
                        // Append the lowercase version of the current input
                        // character (add 0x0020 to the character's code point)
                        // to the current tag token's tag name.
                        $tagToken->tagName .= strtolower($c);
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the current tag token's tag name.
                        $tagToken->tagName .= self::REPLACEMENT_CHAR;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append the current input character to the current tag
                        // token's tag name.
                        $tagToken->tagName .= $c;
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#rcdata-less-than-sign-state
                case TokenizerState::RCDATA_LESS_THAN_SIGN:
                    $c = $this->inputStream->get();

                    if ($c === '/') {
                        // Set the temporary buffer to the empty string. Switch
                        // to the RCDATA end tag open state.
                        $buffer = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::RCDATA_END_TAG_OPEN
                        );
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token.
                        // Reconsume in the RCDATA state.
                        yield new CharacterToken('<');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::RCDATA
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#rcdata-end-tag-open-state
                case TokenizerState::RCDATA_END_TAG_OPEN:
                    $c = $this->inputStream->get();

                    if (ctype_alpha($c)) {
                        // Create a new end tag token, set its tag name to the
                        // empty string. Reconsume in the RCDATA end tag name
                        // state.
                        $tagToken = new EndTagToken('');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::RCDATA_END_TAG_NAME
                        );
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token and a
                        // U+002F SOLIDUS character token. Reconsume in the
                        // RCDATA state.
                        yield new CharacterToken('<');
                        yield new CharacterToken('/');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::RCDATA
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#rcdata-end-tag-name-state
                case TokenizerState::RCDATA_END_TAG_NAME:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the before attribute name
                        // state. Otherwise, treat it as per the "anything else"
                        // entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::BEFORE_ATTRIBUTE_NAME
                            );
                        } else {

                            yield new CharacterToken('<');
                            yield new CharacterToken('/');
                            $streamBuffer = new CodePointStream($buffer);

                            while (!$streamBuffer->isEoS()) {
                                yield new CharacterToken(
                                    $streamBuffer->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::RCDATA
                            );
                        }
                    } elseif ($c === '/') {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the self-closing start tag
                        // state. Otherwise, treat it as per the "anything else"
                        // entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::SELF_CLOSING_START_TAG
                            );
                        } else {
                            yield new CharacterToken('<');
                            yield new CharacterToken('/');
                            $streamBuffer = new CodePointStream($buffer);

                            while (!$streamBuffer->isEoS()) {
                                yield new CharacterToken(
                                    $streamBuffer->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::RCDATA
                            );
                        }
                    } elseif ($c === '>') {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the data state and emit the
                        // current tag token. Otherwise, treat it as per the
                        // "anything else" entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::DATA
                            );
                            yield $tagToken;
                        } else {
                            yield new CharacterToken('<');
                            yield new CharacterToken('/');
                            $streamBuffer = new CodePointStream($buffer);

                            while (!$streamBuffer->isEoS()) {
                                yield new CharacterToken(
                                    $streamBuffer->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::RCDATA
                            );
                        }
                    } elseif (ctype_upper($c)) {
                        // Append the lowercase version of the current input
                        // character (add 0x0020 to the character's code point)
                        // to the current tag token's tag name. Append the
                        // current input character to the temporary buffer.
                        $tagToken->tagName .= strtolower($c);
                        $buffer .= $c;
                    } elseif (ctype_lower($c)) {
                        // Append the current input character to the current tag
                        // token's tag name. Append the current input character
                        // to the temporary buffer.
                        $tagToken->tagName .= $c;
                        $buffer .= $c;
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token, a
                        // U+002F SOLIDUS character token, and a character token
                        // for each of the characters in the temporary buffer
                        // (in the order they were added to the buffer).
                        // Reconsume in the RCDATA state.
                        yield new CharacterToken('<');
                        yield new CharacterToken('/');
                        $streamBuffer = new CodePointStream($buffer);

                        while (!$streamBuffer->isEoS()) {
                            yield new CharacterToken($streamBuffer->get());
                        }

                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::RCDATA
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#rawtext-less-than-sign-state
                case TokenizerState::RAWTEXT_LESS_THAN_SIGN:
                    $c = $this->inputStream->get();

                    if ($c === '/') {
                        // Set the temporary buffer to the empty string. Switch
                        // to the RCDATA end tag open state.
                        $buffer = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::RAWTEXT_END_TAG_OPEN
                        );
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token.
                        // Reconsume in the RAWTEXT state.
                        yield new CharacterToken('<');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::RAWTEXT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#rawtext-end-tag-open-state
                case TokenizerState::RAWTEXT_END_TAG_OPEN:
                    $c = $this->inputStream->get();

                    if (ctype_alpha($c)) {
                        // Create a new end tag token, set its tag name to the
                        // empty string. Reconsume in the RAWTEXT end tag name
                        // state.
                        $tagToken = new EndTagToken('');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::RAWTEXT_END_TAG_NAME
                        );
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token and a
                        // U+002F SOLIDUS character token. Reconsume in the
                        // RAWTEXT state.
                        yield new CharacterToken('<');
                        yield new CharacterToken('/');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::RAWTEXT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#rawtext-end-tag-name-state
                case TokenizerState::RAWTEXT_END_TAG_NAME:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the before attribute name
                        // state. Otherwise, treat it as per the "anything else"
                        // entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::BEFORE_ATTRIBUTE_NAME
                            );
                        } else {
                            yield new CharacterToken('<');
                            yield new CharacterToken('/');

                            $bufferStream = new CodePointStream($buffer);

                            while (!$bufferStream->isEoS()) {
                                yield new CharacterToken(
                                    $bufferStream->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::RAWTEXT
                            );
                        }
                    } elseif ($c === '/') {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the self-closing start tag
                        // state. Otherwise, treat it as per the "anything else"
                        // entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::SELF_CLOSING_START_TAG
                            );
                        } else {
                            yield new CharacterToken('<');
                            yield new CharacterToken('/');

                            $bufferStream = new CodePointStream($buffer);

                            while (!$bufferStream->isEoS()) {
                                yield new CharacterToken(
                                    $bufferStream->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::RAWTEXT
                            );
                        }
                    } elseif ($c === '>') {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the data state and emit the
                        // current tag token. Otherwise, treat it as per the
                        // "anything else" entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::DATA
                            );
                            yield $tagToken;
                        } else {
                            yield new CharacterToken('<');
                            yield new CharacterToken('/');

                            $bufferStream = new CodePointStream($buffer);

                            while (!$bufferStream->isEoS()) {
                                yield new CharacterToken(
                                    $bufferStream->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::RAWTEXT
                            );
                        }
                    } elseif (ctype_upper($c)) {
                        // Append the lowercase version of the current input
                        // character (add 0x0020 to the character's code point)
                        // to the current tag token's tag name. Append the
                        // current input character to the temporary buffer.
                        $tagToken->tagName .= strtolower($c);
                        $buffer .= $c;
                    } elseif (ctype_lower($c)) {
                        // Append the current input character to the current tag
                        // token's tag name. Append the current input character
                        // to the temporary buffer.
                        $tagToken->tagName .= $c;
                        $buffer .= $c;
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token, a
                        // U+002F SOLIDUS character token, and a character token
                        // for each of the characters in the temporary buffer
                        // (in the order they were added to the buffer).
                        // Reconsume in the RAWTEXT state.
                        yield new CharacterToken('<');
                        yield new CharacterToken('/');

                        $bufferStream = new CodePointStream($buffer);

                        while (!$bufferStream->isEoS()) {
                            yield new CharacterToken($bufferStream->get());
                        }

                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::RAWTEXT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-less-than-sign-state
                case TokenizerState::SCRIPT_DATA_LESS_THAN_SIGN:
                    $c = $this->inputStream->get();

                    if ($c === '/') {
                        // Set the temporary buffer to the empty string. Switch
                        // to the script data end tag open state.
                        $buffer = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_END_TAG_OPEN
                        );
                    } elseif ($c === '!') {
                        // Switch to the script data escape start state. Emit a
                        // U+003C LESS-THAN SIGN character token and a U+0021
                        // EXCLAMATION MARK character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPE_START
                        );
                        yield new CharacterToken('<');
                        yield new CharacterToken('!');
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token.
                        // Reconsume in the script data state.
                        yield new CharacterToken('<');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-end-tag-open-state
                case TokenizerState::SCRIPT_DATA_END_TAG_OPEN:
                    $c = $this->inputStream->get();

                    if (ctype_alpha($c)) {
                        // Create a new end tag token, set its tag name to the
                        // empty string. Reconsume in the script data end tag
                        // name state.
                        $tagToken = new EndTagToken('');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_END_TAG_NAME
                        );
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token and a
                        // U+002F SOLIDUS character token. Reconsume in the
                        // script data state.
                        yield new CharacterToken('<');
                        yield new CharacterToken('/');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-end-tag-name-state
                case TokenizerState::SCRIPT_DATA_END_TAG_NAME:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the before attribute name
                        // state. Otherwise, treat it as per the "anything else"
                        // entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::BEFORE_ATTRIBUTE_NAME
                            );
                        } else {
                            yield new CharacterToken('<');
                            yield new CharacterToken('/');

                            $bufferStream = new CodePointStream($buffer);

                            while (!$bufferStream->isEoS()) {
                                yield new CharacterToken(
                                    $bufferStream->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::SCRIPT_DATA
                            );
                        }
                    } elseif ($c === '/') {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the self-closing start tag
                        // state. Otherwise, treat it as per the "anything else"
                        // entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::SELF_CLOSING_START_TAG
                            );
                        } else {
                            yield new CharacterToken('<');
                            yield new CharacterToken('/');

                            $bufferStream = new CodePointStream($buffer);

                            while (!$bufferStream->isEoS()) {
                                yield new CharacterToken(
                                    $bufferStream->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::SCRIPT_DATA
                            );
                        }
                    } elseif ($c === '>') {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the data state and emit the
                        // current tag token. Otherwise, treat it as per the
                        // "anything else" entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::DATA
                            );
                            yield $tagToken;
                        } else {
                            yield new CharacterToken('<');
                            yield new CharacterToken('/');

                            $bufferStream = new CodePointStream($buffer);

                            while (!$bufferStream->isEoS()) {
                                yield new CharacterToken(
                                    $bufferStream->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::SCRIPT_DATA
                            );
                        }
                    } elseif (ctype_upper($c)) {
                        // Append the lowercase version of the current input
                        // character (add 0x0020 to the character's code point)
                        // to the current tag token's tag name. Append the
                        // current input character to the temporary buffer.
                        $tagToken->tagName .= strtolower($c);
                        $buffer .= $c;
                    } elseif (ctype_lower($c)) {
                        // Append the current input character to the current tag
                        // token's tag name. Append the current input character
                        // to the temporary buffer.
                        $tagToken->tagName .= $c;
                        $buffer .= $c;
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token, a
                        // U+002F SOLIDUS character token, and a character token
                        // for each of the characters in the temporary buffer
                        // (in the order they were added to the buffer).
                        // Reconsume in the script data state.
                        yield new CharacterToken('<');
                        yield new CharacterToken('/');

                        $bufferStream = new CodePointStream($buffer);

                        while (!$bufferStream->isEoS()) {
                            yield new CharacterToken($bufferStream->get());
                        }

                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-escape-start-state
                case TokenizerState::SCRIPT_DATA_ESCAPE_START:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Switch to the script data escape start dash state.
                        // Emit a U+002D HYPHEN-MINUS character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPE_START_DASH
                        );
                        yield new CharacterToken('-');
                    } else {
                        // Reconsume in the script data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-escape-start-dash-state
                case TokenizerState::SCRIPT_DATA_ESCAPE_START_DASH:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Switch to the script data escaped dash dash state.
                        // Emit a U+002D HYPHEN-MINUS character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED_DASH_DASH
                        );
                        yield new CharacterToken('-');
                    } else {
                        // Reconsume in the script data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-escaped-state
                case TokenizerState::SCRIPT_DATA_ESCAPED:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Switch to the script data escaped dash state. Emit a
                        // U+002D HYPHEN-MINUS character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED_DASH
                        );
                        yield new CharacterToken('-');
                    } elseif ($c === '<') {
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED_LESS_THAN_SIGN
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Emit a U+FFFD REPLACEMENT CHARACTER character token.
                        yield new CharacterToken(self::REPLACEMENT_CHAR);
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Emit the current input character as a character token.
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-escaped-dash-state
                case TokenizerState::SCRIPT_DATA_ESCAPED_DASH:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Switch to the script data escaped dash state. Emit a
                        // U+002D HYPHEN-MINUS character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED_DASH_DASH
                        );
                        yield new CharacterToken('-');
                    } elseif ($c === '<') {
                        // Switch to the script data escaped less-than sign state.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED_LESS_THAN_SIGN
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Emit a U+FFFD REPLACEMENT CHARACTER character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED
                        );
                        yield new CharacterToken(self::REPLACEMENT_CHAR);
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Switch to the script data escaped state. Emit the
                        // current input character as a character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED
                        );
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-escaped-dash-dash-state
                case TokenizerState::SCRIPT_DATA_ESCAPED_DASH_DASH:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Emit a U+002D HYPHEN-MINUS character token.
                        yield new CharacterToken('-');
                    } elseif ($c === '<') {
                        // Switch to the script data escaped less-than sign state.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED_LESS_THAN_SIGN
                        );
                    } elseif ($c === '>') {
                        // Switch to the script data state. Emit a
                        // U+003E GREATER-THAN SIGN character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA
                        );
                        yield new CharacterToken('>');
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Switch to the script data escaped state. Emit a
                        // U+FFFD REPLACEMENT CHARACTER character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED
                        );
                        yield new CharacterToken(self::REPLACEMENT_CHAR);
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Switch to the script data escaped state. Emit the
                        // current input character as a character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED
                        );
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-escaped-less-than-sign-state
                case TokenizerState::SCRIPT_DATA_ESCAPED_LESS_THAN_SIGN:
                    $c = $this->inputStream->get();

                    if ($c === '/') {
                        // Set the temporary buffer to the empty string. Switch
                        // to the script data escaped end tag open state.
                        $buffer = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED_END_TAG_OPEN
                        );
                    } elseif (ctype_alpha($c)) {
                        // Set the temporary buffer to the empty string. Emit a
                        // U+003C LESS-THAN SIGN character token. Reconsume in
                        // the script data double escape start state.
                        $buffer = '';
                        yield new CharacterToken('<');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPE_START
                        );
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token.
                        // Reconsume in the script data escaped state.
                        yield new CharacterToken('<');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-escaped-end-tag-open-state
                case TokenizerState::SCRIPT_DATA_ESCAPED_END_TAG_OPEN:
                    $c = $this->inputStream->get();

                    if (ctype_alpha($c)) {
                        // Create a new end tag token. Reconsume in the script
                        // data escaped end tag name state. (Don't emit the
                        // token yet; further details will be filled in before
                        // it is emitted.)
                        $tagToken = new EndTagToken();
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED_END_TAG_NAME
                        );
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token and a
                        // U+002F SOLIDUS character token. Reconsume in the
                        // script data escaped state.
                        yield new CharacterToken('<');
                        yield new CharacterToken('/');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-escaped-end-tag-name-state
                case TokenizerState::SCRIPT_DATA_ESCAPED_END_TAG_NAME:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the before attribute name
                        // state. Otherwise, treat it as per the "anything else"
                        // entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::BEFORE_ATTRIBUTE_NAME
                            );
                        } else {
                            yield new CharacterToken('<');
                            yield new CharacterToken('/');

                            $bufferStream = new CodePointStream($buffer);

                            while (!$bufferStream->isEoS()) {
                                yield new CharacterToken(
                                    $bufferStream->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::SCRIPT_DATA
                            );
                        }
                    } elseif ($c === '/') {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the self-closing start tag
                        // state. Otherwise, treat it as per the "anything else"
                        // entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::SELF_CLOSING_START_TAG
                            );
                        } else {
                            yield new CharacterToken('<');
                            yield new CharacterToken('/');

                            $bufferStream = new CodePointStream($buffer);

                            while (!$bufferStream->isEoS()) {
                                yield new CharacterToken(
                                    $bufferStream->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::SCRIPT_DATA
                            );
                        }
                    } elseif ($c === '>') {
                        // If the current end tag token is an appropriate end
                        // tag token, then switch to the data state and emit the
                        // current tag token. Otherwise, treat it as per the
                        // "anything else" entry below.
                        $isAppropriateEndTag = $this->isAppropriateEndTag(
                            $tagToken
                        );

                        if ($isAppropriateEndTag) {
                            $this->parser->setTokenizerState(
                                TokenizerState::DATA
                            );
                            yield $tagToken;
                        } else {
                            yield new CharacterToken('<');
                            yield new CharacterToken('/');

                            $bufferStream = new CodePointStream($buffer);

                            while (!$bufferStream->isEoS()) {
                                yield new CharacterToken(
                                    $bufferStream->get()
                                );
                            }

                            $this->inputStream->seek(
                                -1,
                                CodePointStream::SEEK_RELATIVE
                            );
                            $this->parser->setTokenizerState(
                                TokenizerState::SCRIPT_DATA
                            );
                        }
                    } elseif (ctype_upper($c)) {
                        // Append the lowercase version of the current input
                        // character (add 0x0020 to the character's code point)
                        // to the current tag token's tag name. Append the
                        // current input character to the temporary buffer.
                        $tagToken->tagName .= strtolower($c);
                        $buffer .= $c;
                    } elseif (ctype_lower($c)) {
                        // Append the current input character to the current tag
                        // token's tag name. Append the current input character
                        // to the temporary buffer.
                        $tagToken->tagName .= $c;
                        $buffer .= $c;
                    } else {
                        // Emit a U+003C LESS-THAN SIGN character token, a
                        // U+002F SOLIDUS character token, and a character
                        // token for each of the characters in the temporary
                        // buffer (in the order they were added to the buffer).
                        // Reconsume in the script data escaped state.
                        yield new CharacterToken('<');
                        yield new CharacterToken('/');

                        $bufferStream = new CodePointStream($buffer);

                        while (!$bufferStream->isEoS()) {
                            yield new CharacterToken($bufferStream->get());
                        }

                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-double-escape-start-state
                case TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPE_START:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20" ||
                        $c === '/' ||
                        $c === '>'
                    ) {
                        // If the temporary buffer is the string "script",
                        // then switch to the script data double escaped state.
                        // Otherwise, switch to the script data escaped state.
                        // Emit the current input character as a character token.
                        if ($buffer === 'script') {
                            $this->parser->setTokenizerState(
                                TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED
                            );
                        } else {
                            $this->parser->setTokenizerState(
                                TokenizerState::SCRIPT_DATA_ESCAPED
                            );
                        }

                        yield new CharacterToken($c);
                    } elseif (ctype_upper($c)) {
                        // Append the lowercase version of the current input
                        // character (add 0x0020 to the character's code point)
                        // to the temporary buffer. Emit the current input
                        // character as a character token.
                        $buffer .= strtolower($c);
                        yield new CharacterToken($c);
                    } elseif (ctype_lower($c)) {
                        // Append the current input character to the temporary
                        // buffer. Emit the current input character as a
                        // character token.
                        $buffer .= $c;
                        yield new CharacterToken($c);
                    } else {
                        // Reconsume in the script data escaped state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_ESCAPED
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-double-escaped-state
                case TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Switch to the script data double escaped dash state.
                        // Emit a U+002D HYPHEN-MINUS character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED_DASH
                        );
                        yield new CharacterToken('-');
                    } elseif ($c === '<') {
                        // Switch to the script data double escaped less-than
                        // sign state. Emit a U+003C LESS-THAN SIGN character
                        // token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED_LESS_THAN_SIGN
                        );
                        yield new CharacterToken('<');
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Emit a U+FFFD REPLACEMENT CHARACTER character token.
                        yield new CharacterToken(self::REPLACEMENT_CHAR);
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Emit the current input character as a character
                        // token.
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-double-escaped-dash-state
                case TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED_DASH:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Switch to the script data double escaped dash dash
                        // state. Emit a U+002D HYPHEN-MINUS character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED_DASH_DASH
                        );
                        yield new CharacterToken('-');
                    } elseif ($c === '<') {
                        // Switch to the script data double escaped less-than
                        // sign state. Emit a U+003C LESS-THAN SIGN character
                        // token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED_LESS_THAN_SIGN
                        );
                        yield new CharacterToken('<');
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Switch to the script data double escaped state. Emit
                        // a U+FFFD REPLACEMENT CHARACTER character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED
                        );
                        yield new CharacterToken(self::REPLACEMENT_CHAR);
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Switch to the script data double escaped state. Emit
                        // the current input character as a character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED
                        );
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-double-escaped-dash-dash-state
                case TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED_DASH_DASH:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Emit a U+002D HYPHEN-MINUS character token.
                        yield new CharacterToken('-');
                    } elseif ($c === '<') {
                        // Switch to the script data double escaped less-than
                        // sign state. Emit a U+003C LESS-THAN SIGN character
                        // token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED_LESS_THAN_SIGN
                        );
                        yield new CharacterToken('<');
                    } elseif ($c === '>') {
                        // Switch to the script data state. Emit a U+003E
                        // GREATER-THAN SIGN character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA
                        );
                        yield new CharacterToken('>');
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Switch to the script data double escaped state. Emit
                        // a U+FFFD REPLACEMENT CHARACTER character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED
                        );
                        yield new CharacterToken(self::REPLACEMENT_CHAR);
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Switch to the script data double escaped state. Emit
                        // the current input character as a character token.
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED
                        );
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-double-escaped-less-than-sign-state
                case TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED_LESS_THAN_SIGN:
                    $c = $this->inputStream->get();

                    if ($c === '/') {
                        // Set the temporary buffer to the empty string. Switch
                        // to the script data double escape end state. Emit a
                        // U+002F SOLIDUS character token.
                        $buffer = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPE_END
                        );
                        yield new CharacterToken('/');
                    } else {
                        // Reconsume in the script data double escaped state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#script-data-double-escape-end-state
                case TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPE_END:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20" ||
                        $c === "\x2F" ||
                        $c === "\x3E"
                    ) {
                        // If the temporary buffer is the string "script", then
                        // switch to the script data escaped state. Otherwise,
                        // switch to the script data double escaped state. Emit
                        // the current input character as a character token.
                        if ($buffer === 'script') {
                            $this->parser->setTokenizerState(
                                TokenizerState::SCRIPT_DATA_ESCAPED
                            );
                        } else {
                            $this->parser->setTokenizerState(
                                TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED
                            );
                        }

                        yield new CharacterToken($c);
                    } elseif (ctype_upper($c)) {
                        // Append the lowercase version of the current input
                        // character (add 0x0020 to the character's code point)
                        // to the temporary buffer. Emit the current input
                        // character as a character token.
                        $buffer .= strtolower($c);
                        yield new CharacterToken($c);
                    } elseif (ctype_lower($c)) {
                        // Append the current input character to the temporary
                        // buffer. Emit the current input character as a
                        // character token.
                        $buffer .= $c;
                        yield new CharacterToken($c);
                    } else {
                        // Reconsume in the script data double escaped state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::SCRIPT_DATA_DOUBLE_ESCAPED
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#before-attribute-name-state
                case TokenizerState::BEFORE_ATTRIBUTE_NAME:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Ignore the character.
                    } elseif ($c === '/' || $c === '>' ||
                        $this->inputStream->isEoS()
                    ) {
                        // Reconsume in the after attribute name state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::AFTER_ATTRIBUTE_NAME
                        );
                    } elseif ($c === '=') {
                        // Parse error.
                        // Start a new attribute in the current tag token. Set
                        // that attribute's name to the current input character,
                        // and its value to the empty string. Switch to the
                        // attribute name state.
                        $attributeToken = new AttributeToken($c, '');
                        $tagToken->attributes->push($attributeToken);
                        $this->parser->setTokenizerState(
                            TokenizerState::ATTRIBUTE_NAME
                        );
                    } else {
                        // Start a new attribute in the current tag token. Set
                        // that attribute name and value to the empty string.
                        // Reconsume in the attribute name state.
                        $attributeToken = new AttributeToken('', '');
                        $tagToken->attributes->push($attributeToken);
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::ATTRIBUTE_NAME
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#attribute-name-state
                case TokenizerState::ATTRIBUTE_NAME:
                    $c = $this->inputStream->get();
                    $state = TokenizerState::ATTRIBUTE_NAME;

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20" ||
                        $c === '/' ||
                        $c === '>' ||
                        $this->inputStream->isEoS()
                    ) {
                        // Reconsume in the after attribute name state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $state = TokenizerState::AFTER_ATTRIBUTE_NAME;
                        $this->parser->setTokenizerState($state);
                    } elseif ($c === '=') {
                        // Switch to the before attribute value state.
                        $state = TokenizerState::BEFORE_ATTRIBUTE_VALUE;
                        $this->parser->setTokenizerState($state);
                    } elseif (ctype_upper($c)) {
                        // Append the lowercase version of the current input
                        // character (add 0x0020 to the character's code point)
                        // to the current attribute's name.
                        $attributeToken->name .= strtolower($c);
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the current attribute's name.
                        $attributeToken->name .= self::REPLACEMENT_CHAR;
                    } elseif ($c === '"' || $c === '\'' || $c === '<') {
                        // Parse error.
                        // Treat it as per the "anything else" entry below.
                        $attributeToken->name .= $c;
                    } else {
                        // Append the current input character to the current
                        // attribute's name.
                        $attributeToken->name .= $c;
                    }

                    // When the user agent leaves the attribute name state
                    // (and before emitting the tag token, if appropriate), the
                    // complete attribute's name must be compared to the other
                    // attributes on the same token; if there is already an
                    // attribute on the token with the exact same name, then
                    // this is a parse error and the new attribute must be
                    // removed from the token.
                    if ($state != TokenizerState::ATTRIBUTE_NAME) {
                        $state = TokenizerState::ATTRIBUTE_NAME;
                        $attributes = $tagToken->attributes;
                        $attrName = $attributeToken->name;

                        foreach ($attributes as $attr) {
                            if ($attr->name === $attrName &&
                                $attr !== $attributeToken) {
                                // Parse error.
                                $attributes->pop();
                                break;
                            }
                        }
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#after-attribute-name-state
                case TokenizerState::AFTER_ATTRIBUTE_NAME:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Ignore the character.
                    } elseif ($c === '/') {
                        // Switch to the self-closing start tag state.
                        $this->parser->setTokenizerState(
                            TokenizerState::SELF_CLOSING_START_TAG
                        );
                    } elseif ($c === '=') {
                        // Switch to the before attribute value state.
                        $this->parser->setTokenizerState(
                            TokenizerState::BEFORE_ATTRIBUTE_VALUE
                        );
                    } elseif ($c === '>') {
                        // Switch to the data state. Emit the current tag token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $tagToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Start a new attribute in the current tag token. Set
                        // that attribute name and value to the empty string.
                        // Reconsume in the attribute name state.
                        $attributeToken = new AttributeToken('', '');
                        $tagToken->attributes->push($attributeToken);
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::ATTRIBUTE_NAME
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#before-attribute-value-state
                case TokenizerState::BEFORE_ATTRIBUTE_VALUE:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Ignore the character.
                    } elseif ($c === '"') {
                        // Switch to the attribute value (double-quoted) state.
                        $this->parser->setTokenizerState(
                            TokenizerState::ATTRIBUTE_VALUE_DOUBLE_QUOTED
                        );
                    } elseif ($c === '\'') {
                        // Switch to the attribute value (single-quoted) state.
                        $this->parser->setTokenizerState(
                            TokenizerState::ATTRIBUTE_VALUE_SINGLE_QUOTED
                        );
                    } elseif ($c === '>') {
                        // Parse error.
                        // Treat it as per the "anything else" entry below.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::ATTRIBUTE_VALUE_UNQUOTED
                        );
                    } else {
                        // Reconsume in the attribute value (unquoted) state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::ATTRIBUTE_VALUE_UNQUOTED
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#attribute-value-(double-quoted)-state
                case TokenizerState::ATTRIBUTE_VALUE_DOUBLE_QUOTED:
                    $c = $this->inputStream->get();

                    if ($c === '"') {
                        // Switch to the after attribute value (quoted) state.
                        $this->parser->setTokenizerState(
                            TokenizerState::AFTER_ATTRIBUTE_VALUE_QUOTED
                        );
                    } elseif ($c === '&') {
                        // Set the return state to the attribute value
                        // (double-quoted) state. Switch to the character
                        // reference state.
                        $returnState =
                            TokenizerState::ATTRIBUTE_VALUE_DOUBLE_QUOTED;
                        $this->parser->setTokenizerState(
                            TokenizerState::CHARACTER_REFERENCE
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the current attribute's value.
                        $attributeToken->value .= self::REPLACEMENT_CHAR;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append the current input character to the current
                        // attribute's value.
                        $attributeToken->value .= $c;
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#attribute-value-(single-quoted)-state
                case TokenizerState::ATTRIBUTE_VALUE_SINGLE_QUOTED:
                    $c = $this->inputStream->get();

                    if ($c === '\'') {
                        // Switch to the after attribute value (quoted) state.
                        $this->parser->setTokenizerState(
                            TokenizerState::AFTER_ATTRIBUTE_VALUE_QUOTED
                        );
                    } elseif ($c === '&') {
                        // Set the return state to the attribute value
                        // (single-quoted) state. Switch to the character
                        // reference state.
                        $returnState =
                            TokenizerState::ATTRIBUTE_VALUE_SINGLE_QUOTED;
                        $this->parser->setTokenizerState(
                            TokenizerState::CHARACTER_REFERENCE
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the current attribute's value.
                        $attributeToken->value .= self::REPLACEMENT_CHAR;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append the current input character to the current
                        // attribute's value.
                        $attributeToken->value .= $c;
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#attribute-value-(unquoted)-state
                case TokenizerState::ATTRIBUTE_VALUE_UNQUOTED:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Switch to the before attribute name state.
                        $this->parser->setTokenizerState(
                            TokenizerState::BEFORE_ATTRIBUTE_NAME
                        );
                    } elseif ($c === '&') {
                        // Set the return state to the attribute value
                        // (unquoted) state. Switch to the character reference
                        // state.
                        $returnState = TokenizerState::ATTRIBUTE_VALUE_UNQUOTED;
                        $this->parser->setTokenizerState(
                            TokenizerState::CHARACTER_REFERENCE
                        );
                    } elseif ($c === '>') {
                        // Switch to the data state. Emit the current tag token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $tagToken;
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the current attribute's value.
                        $attributeToken->value .= self::REPLACEMENT_CHAR;
                    } elseif ($c === '"' ||
                        $c === '\'' ||
                        $c === '<' ||
                        $c === '=' ||
                        $c === '`'
                    ) {
                        // Parse error.
                        // Treat it as per the "anything else" entry below.
                        $attributeToken->value .= $c;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append the current input character to the current
                        // attribute's value.
                        $attributeToken->value .= $c;
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#after-attribute-value-(quoted)-state
                case TokenizerState::AFTER_ATTRIBUTE_VALUE_QUOTED:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Switch to the before attribute name state.
                        $this->parser->setTokenizerState(
                            TokenizerState::BEFORE_ATTRIBUTE_NAME
                        );
                    } elseif ($c === '/') {
                        // Switch to the self-closing start tag state.
                        $this->parser->setTokenizerState(
                            TokenizerState::SELF_CLOSING_START_TAG
                        );
                    } elseif ($c === '>') {
                        // Switch to the data state. Emit the current tag token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $tagToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::DATA
                        );
                    } else {
                        // Parse error.
                        // Reconsume in the before attribute name state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::BEFORE_ATTRIBUTE_NAME
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#self-closing-start-tag-state
                case TokenizerState::SELF_CLOSING_START_TAG:
                    $c = $this->inputStream->get();

                    if ($c === '>') {
                        // Set the self-closing flag of the current tag token.
                        // Switch to the data state. Emit the current tag token.
                        $tagToken->setSelfClosingFlag();
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $tagToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::DATA
                        );
                    } else {
                        // Parse error.
                        // Reconsume in the before attribute name state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::BEFORE_ATTRIBUTE_NAME
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#bogus-comment-state
                case TokenizerState::BOGUS_COMMENT:
                    $c = $this->inputStream->get();

                    if ($c === '>') {
                        // Switch to the data state. Emit the comment token.
                        $this->parser->setTokenizerState(
                            TokenizerState::DATA
                        );
                        yield $commentToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Emit the comment. Reconsume in the data state.
                        yield $commentToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } elseif ($c === "\0") {
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the comment token's data.
                        $commentToken->data .= self::REPLACEMENT_CHAR;
                    } else {
                        // Append the current input character to the comment
                        // token's data.
                        $commentToken->data .= $c;
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#markup-declaration-open-state
                case TokenizerState::MARKUP_DECLARATION_OPEN:
                    // If the next two characters are both U+002D HYPHEN-MINUS
                    // characters (-), consume those two characters, create a
                    // comment token whose data is the empty string, and switch
                    // to the comment start state.
                    if ($this->inputStream->peek(2) === '--') {
                        $this->inputStream->get(2);
                        $commentToken = new CommentToken('');
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_START
                        );
                    } elseif (strcasecmp(
                        $this->inputStream->peek(7),
                        'DOCTYPE'
                    ) === 0) {
                        // Otherwise, if the next seven characters are an ASCII
                        // case-insensitive match for the word "DOCTYPE", then
                        // consume those characters and switch to the DOCTYPE
                        // state.
                        $this->inputStream->get(7);
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE
                        );
                    } elseif (($n = $this->parser->getAdjustedCurrentNode()) &&
                        !($n->nodeType == $n::ELEMENT_NODE &&
                            $n->namespaceURI === Namespaces::HTML) &&
                        $this->inputStream->peek(7) === '[CDATA['
                    ) {
                        // Otherwise, if there is an adjusted current node and
                        // it is not an element in the HTML namespace and the
                        // next seven characters are a case-sensitive match for
                        // the string "[CDATA[" (the five uppercase letters
                        // "CDATA" with a U+005B LEFT SQUARE BRACKET character
                        // before and after), then consume those characters and
                        // switch to the CDATA section state.
                        $this->inputStream->get(7);
                        $this->parser->setTokenizerState(
                            TokenizerState::CDATA_SECTION
                        );
                    } else {
                        // Otherwise, this is a parse error. Create a comment
                        // token whose data is the empty string. Switch to the
                        // bogus comment state (don't consume anything in the
                        // current state).
                        $commentToken = new CommentToken('');
                        $this->parser->setTokenizerState(
                            TokenizerState::BOGUS_COMMENT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#comment-start-state
                case TokenizerState::COMMENT_START:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Switch to the comment start dash state.
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_START_DASH
                        );
                    } elseif ($c === '>') {
                        // Parse error.
                        // Switch to the data state. Emit the comment token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $commentToken;
                    } else {
                        // Reconsume in the comment state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#comment-start-dash-state
                case TokenizerState::COMMENT_START_DASH:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Switch to the comment end state
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_END
                        );
                    } elseif ($c === '>') {
                        // Parse error.
                        // Switch to the data state. Emit the comment token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $commentToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Emit the comment token. Reconsume in the data state.
                        yield $commentToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append a U+002D HYPHEN-MINUS character (-) to the
                        // comment token's data. Reconsume in the comment state.
                        $commentToken->data .= '-';
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#comment
                case TokenizerState::COMMENT:
                    $c = $this->inputStream->get();

                    if ($c === '<') {
                        // Append the current input character to the comment
                        // token's data. Switch to the comment less-than sign
                        // state.
                        $commentToken->data .= $c;
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_LESS_THAN_SIGN
                        );
                    } elseif ($c === '-') {
                        // Switch to the comment end dash state
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_END_DASH
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the comment token's data.
                        $commentToken->data .= self::REPLACEMENT_CHAR;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Emit the comment token. Reconsume in the data state.
                        yield $commentToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append the current input character to the comment
                        // token's data.
                        $commentToken->data .= $c;
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#comment-less-than-sign-state
                case TokenizerState::COMMENT_LESS_THAN_SIGN:
                    $c = $this->inputStream->get();

                    if ($c === '!') {
                        // Append the current input character to the comment
                        // token's data. Switch to the comment less-than sign
                        // bang state.
                        $commentToken->data .= $c;
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_LESS_THAN_SIGN_BANG
                        );
                    } elseif ($c === '<') {
                        // Append the current input character to the comment
                        // token's data.
                        $commentToken->data .= $c;
                    } else {
                        // Reconsume in the comment state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#comment-less-than-sign-bang-state
                case TokenizerState::COMMENT_LESS_THAN_SIGN_BANG:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Switch to the comment less-than sign bang dash state.
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_LESS_THAN_SIGN_BANG_DASH
                        );
                    } else {
                        //Reconsume in the comment state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#comment-less-than-sign-bang-dash-state
                case TokenizerState::COMMENT_LESS_THAN_SIGN_BANG_DASH:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Switch to the comment less-than sign bang dash dash
                        // state.
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_LESS_THAN_SIGN_BANG_DASH_DASH
                        );
                    } else {
                        // Reconsume in the comment end dash state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_END_DASH
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#comment-less-than-sign-bang-dash-dash-state
                case TokenizerState::COMMENT_LESS_THAN_SIGN_BANG_DASH_DASH:
                    $c = $this->inputStream->get();

                    if ($c === '>' || $this->inputStream->isEoS()) {
                        // Reconsume in the comment end state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_END
                        );
                    } else {
                        // Parse error.
                        // Reconsume in the comment end state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_END
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#comment-end-dash-state
                case TokenizerState::COMMENT_END_DASH:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Switch to the comment end state
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_END
                        );
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Emit the comment token. Reconsume in the data state.
                        yield $commentToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append a U+002D HYPHEN-MINUS character (-) to the
                        // comment token's data. Reconsume in the comment state.
                        $commentToken->data .= '-';
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#comment-end-state
                case TokenizerState::COMMENT_END:
                    $c = $this->inputStream->get();

                    if ($c === '>') {
                        // Switch to the data state. Emit the comment token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $commentToken;
                    } elseif ($c === '!') {
                        // Switch to the comment end bang state.
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_END_BANG
                        );
                    } elseif ($c === '-') {
                        // Append a U+002D HYPHEN-MINUS character (-) to the
                        // comment token's data.
                        $commentToken->data .= '-';
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Emit the comment token. Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Parse error.
                        // Append two U+002D HYPHEN-MINUS characters (-) to the
                        // comment token's data. Reconsume in the comment state.
                        $commentToken->data .= '--';
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#comment-end-bang-state
                case TokenizerState::COMMENT_END_BANG:
                    $c = $this->inputStream->get();

                    if ($c === '-') {
                        // Append two U+002D HYPHEN-MINUS characters (-) and a
                        // U+0021 EXCLAMATION MARK character (!) to the comment
                        // token's data. Switch to the comment end dash state.
                        $commentToken->data .= '--!';
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT_END_DASH
                        );
                    } elseif ($c === '>') {
                        // Switch to the data state. Emit the comment token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $commentToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Emit the comment token. Reconsume in the data state.
                        yield $commentToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append two U+002D HYPHEN-MINUS characters (-),
                        // a U+0021 EXCLAMATION MARK character (!), and the
                        // current input character to the comment token's data.
                        // Switch to the comment state
                        $commentToken->data .= '--!';
                        $this->parser->setTokenizerState(
                            TokenizerState::COMMENT
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#doctype-state
                case TokenizerState::DOCTYPE:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Switch to the before DOCTYPE name state.
                        $this->parser->setTokenizerState(
                            TokenizerState::BEFORE_DOCTYPE_NAME
                        );
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Create a new DOCTYPE token. Set its force-quirks flag
                        // to on. Emit the token. Reconsume in the data state.
                        $doctypeToken = new DoctypeToken();
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Parse error.
                        // Reconsume in the before DOCTYPE name state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::BEFORE_DOCTYPE_NAME
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#before-doctype-name-state
                case TokenizerState::BEFORE_DOCTYPE_NAME:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Ignore the character.
                    } elseif (ctype_upper($c)) {
                        // Create a new DOCTYPE token. Set the token's name to
                        // the lowercase version of the current input character
                        // (add 0x0020 to the character's code point). Switch to
                        // the DOCTYPE name state.
                        $doctypeToken = new DoctypeToken();
                        $doctypeToken->name = strtolower($c);
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_NAME
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Create a new DOCTYPE token. Set the token's name to a
                        // U+FFFD REPLACEMENT CHARACTER character. Switch to the
                        // DOCTYPE name state.
                        $doctypeToken = new DoctypeToken();
                        $doctypeToken->name = self::REPLACEMENT_CHAR;
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_NAME
                        );
                    } elseif ($c === '>') {
                        // Parse error.
                        // Create a new DOCTYPE token. Set its force-quirks flag
                        // to on. Switch to the data state. Emit the token.
                        $doctypeToken = new DoctypeToken();
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Create a new DOCTYPE token. Set its force-quirks flag
                        // to on. Emit the token. Reconsume in the data state.
                        $doctypeToken = new DoctypeToken();
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Create a new DOCTYPE token. Set the token's name to
                        // the current input character. Switch to the DOCTYPE
                        // name state.
                        $doctypeToken = new DoctypeToken();
                        $doctypeToken->name = $c;
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_NAME
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#doctype-name-state
                case TokenizerState::DOCTYPE_NAME:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Switch to the after DOCTYPE name state.
                        $this->parser->setTokenizerState(
                            TokenizerState::AFTER_DOCTYPE_NAME
                        );
                    } elseif ($c === '>') {
                        // Switch to the data state. Emit the current DOCTYPE
                        // token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif (ctype_upper($c)) {
                        // Append the lowercase version of the current input
                        // character (add 0x0020 to the character's code point)
                        // to the current DOCTYPE token's name.
                        $doctypeToken->name .= strtolower($c);
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the current DOCTYPE token's name.
                        $doctypeToken->name .= self::REPLACEMENT_CHAR;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append the current input character to the current
                        // DOCTYPE token's name.
                        $doctypeToken->name .= $c;
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#after-doctype-name-state
                case TokenizerState::AFTER_DOCTYPE_NAME:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Ignore the character.
                    } elseif ($c === '>') {
                        // Switch to the data state. Emit the current DOCTYPE
                        // token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks
                        // flag to on. Emit that DOCTYPE token. Reconsume in
                        // the data state.
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        $chars = $c . $this->inputStream->peek(5);

                        // If the six characters starting from the current input
                        // character are an ASCII case-insensitive match for the
                        // word "PUBLIC", then consume those characters and
                        // switch to the after DOCTYPE public keyword state.
                        if (strcasecmp($chars, 'PUBLIC') === 0) {
                            $this->inputStream->get(5);
                            $this->parser->setTokenizerState(
                                TokenizerState::AFTER_DOCTYPE_PUBLIC_KEYWORD
                            );
                        } elseif (strcasecmp($chars, 'SYSTEM') === 0) {
                            // Otherwise, if the six characters starting from
                            // the current input character are an ASCII
                            // case-insensitive match for the word "SYSTEM",
                            // then consume those characters and switch to the
                            // after DOCTYPE system keyword state.
                            $this->inputStream->get(5);
                            $this->parser->setTokenizerState(
                                TokenizerState::AFTER_DOCTYPE_SYSTEM_KEYWORD
                            );
                        } else {
                            // Otherwise, this is a parse error. Set the DOCTYPE
                            // token's force-quirks flag to on. Switch to the
                            // bogus DOCTYPE state.
                            $doctypeToken->setQuirksMode('on');
                            $this->parser->setTokenizerState(
                                TokenizerState::BOGUS_DOCTYPE
                            );
                        }
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#after-doctype-public-keyword-state
                case TokenizerState::AFTER_DOCTYPE_PUBLIC_KEYWORD:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Switch to the before DOCTYPE public identifier state.
                        $this->parser->setTokenizerState(
                            TokenizerState::BEFORE_DOCTYPE_PUBLIC_IDENTIFIER
                        );
                    } elseif ($c === '"') {
                        // Parse error.
                        // Set the DOCTYPE token's public identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE public identifier (double-quoted) state.
                        $doctypeToken->publicIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_PUBLIC_IDENTIFIER_DOUBLE_QUOTED
                        );
                    } elseif ($c === '\'') {
                        // Parse error.
                        // Set the DOCTYPE token's public identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE public identifier (single-quoted) state.
                        $doctypeToken->publicIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_PUBLIC_IDENTIFIER_SINGLE_QUOTED
                        );
                    } elseif ($c === '>') {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the data state. Emit that DOCTYPE token.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the bogus DOCTYPE state.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(
                            TokenizerState::BOGUS_DOCTYPE
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#before-doctype-public-identifier-state
                case TokenizerState::BEFORE_DOCTYPE_PUBLIC_IDENTIFIER:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Ignore the character.
                    } elseif ($c === '"') {
                        // Set the DOCTYPE token's public identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE public identifier (double-quoted) state.
                        $doctypeToken->publicIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_PUBLIC_IDENTIFIER_DOUBLE_QUOTED
                        );
                    } elseif ($c === '\'') {
                        // Set the DOCTYPE token's public identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE public identifier (single-quoted) state.
                        $doctypeToken->publicIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_PUBLIC_IDENTIFIER_SINGLE_QUOTED
                        );
                    } elseif ($c === '>') {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the data state. Emit that DOCTYPE token.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the bogus DOCTYPE state.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(
                            TokenizerState::BOGUS_DOCTYPE
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#doctype-public-identifier-(double-quoted)-state
                case TokenizerState::DOCTYPE_PUBLIC_IDENTIFIER_DOUBLE_QUOTED:
                    $c = $this->inputStream->get();

                    if ($c === '"') {
                        // Switch to the after DOCTYPE public identifier state.
                        $this->parser->setTokenizerState(
                            TokenizerState::AFTER_DOCTYPE_PUBLIC_IDENTIFIER
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the current DOCTYPE token's public identifier.
                        $doctypeToken->publicIdentifier .= self::REPLACEMENT_CHAR;
                    } elseif ($c === '>') {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the data state. Emit that DOCTYPE token.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append the current input character to the current
                        // DOCTYPE token's public identifier.
                        $doctypeToken->publicIdentifier .= $c;
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#doctype-public-identifier-(single-quoted)-state
                case TokenizerState::DOCTYPE_PUBLIC_IDENTIFIER_SINGLE_QUOTED:
                    $c = $this->inputStream->get();

                    if ($c === '\'') {
                        // Switch to the after DOCTYPE public identifier state.
                        $this->parser->setTokenizerState(
                            TokenizerState::AFTER_DOCTYPE_PUBLIC_IDENTIFIER
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the current DOCTYPE token's public identifier.
                        $doctypeToken->publicIdentifier .= self::REPLACEMENT_CHAR;
                    } elseif ($c === '>') {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the data state. Emit that DOCTYPE token.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append the current input character to the current
                        // DOCTYPE token's public identifier.
                        $doctypeToken->publicIdentifier .= $c;
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#after-doctype-public-identifier-state
                case TokenizerState::AFTER_DOCTYPE_PUBLIC_IDENTIFIER:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Switch to the between DOCTYPE public and system
                        // identifiers state.
                        $this->parser->setTokenizerState(
                            TokenizerState::BETWEEN_DOCTYPE_PUBLIC_AND_SYSTEM_IDENTIFIERS
                        );
                    } elseif ($c === '>') {
                        // Switch to the data state. Emit the current DOCTYPE
                        // token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($c === '"') {
                        // Parse error.
                        // Set the DOCTYPE token's system identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE system identifier (double-quoted) state
                        $doctypeToken->systemIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_SYSTEM_IDENTIFIER_DOUBLE_QUOTED
                        );
                    } elseif ($c === '\'') {
                        // Parse error.
                        // Set the DOCTYPE token's system identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE system identifier (single-quoted) state.
                        $doctypeToken->systemIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_SYSTEM_IDENTIFIER_SINGLE_QUOTED
                        );
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the bogus DOCTYPE state.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(
                            TokenizerState::BOGUS_DOCTYPE
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#between-doctype-public-and-system-identifiers-state
                case TokenizerState::BETWEEN_DOCTYPE_PUBLIC_AND_SYSTEM_IDENTIFIERS:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Ignore the character.
                    } elseif ($c === '>') {
                        // Switch to the data state. Emit the current DOCTYPE
                        // token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($c === '"') {
                        // Set the DOCTYPE token's system identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE system identifier (double-quoted) state.
                        $doctypeToken->systemIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_SYSTEM_IDENTIFIER_DOUBLE_QUOTED
                        );
                    } elseif ($c === '\'') {
                        // Set the DOCTYPE token's system identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE system identifier (single-quoted) state.
                        $doctypeToken->systemIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_SYSTEM_IDENTIFIER_SINGLE_QUOTED
                        );
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the bogus DOCTYPE state.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(
                            TokenizerState::BOGUS_DOCTYPE
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#after-doctype-system-keyword-state
                case TokenizerState::AFTER_DOCTYPE_SYSTEM_KEYWORD:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Switch to the before DOCTYPE system identifier state.
                        $this->parser->setTokenizerState(
                            TokenizerState::BEFORE_DOCTYPE_SYSTEM_IDENTIFIER
                        );
                    } elseif ($c === '"') {
                        // Parse error.
                        // Set the DOCTYPE token's system identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE system identifier (double-quoted) state.
                        $doctypeToken->systemIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_SYSTEM_IDENTIFIER_DOUBLE_QUOTED
                        );
                    } elseif ($c === '\'') {
                        // Parse error.
                        // Set the DOCTYPE token's system identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE system identifier (single-quoted) state.
                        $doctypeToken->systemIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_SYSTEM_IDENTIFIER_SINGLE_QUOTED
                        );
                    } elseif ($c === '>') {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the data state. Emit that DOCTYPE token.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the bogus DOCTYPE state.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(
                            TokenizerState::BOGUS_DOCTYPE
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#before-doctype-system-identifier-state
                case TokenizerState::BEFORE_DOCTYPE_SYSTEM_IDENTIFIER:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Ignore the character.
                    } elseif ($c === '"') {
                        // Set the DOCTYPE token's system identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE system identifier (double-quoted) state.
                        $doctypeToken->systemIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_SYSTEM_IDENTIFIER_DOUBLE_QUOTED
                        );
                    } elseif ($c === '\'') {
                        // Set the DOCTYPE token's system identifier to the
                        // empty string (not missing), then switch to the
                        // DOCTYPE system identifier (single-quoted) state.
                        $doctypeToken->systemIdentifier = '';
                        $this->parser->setTokenizerState(
                            TokenizerState::DOCTYPE_SYSTEM_IDENTIFIER_SINGLE_QUOTED
                        );
                    } elseif ($c === '>') {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the data state. Emit that DOCTYPE token.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Parse error. Set the DOCTYPE token's force-quirks
                        // flag to on. Switch to the bogus DOCTYPE state.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(
                            TokenizerState::BOGUS_DOCTYPE
                        );
                    }

                    break;

                case TokenizerState::DOCTYPE_SYSTEM_IDENTIFIER_DOUBLE_QUOTED:
                    $c = $this->inputStream->get();

                    if ($c === '"') {
                        // Switch to the after DOCTYPE system identifier state.
                        $this->parser->setTokenizerState(
                            TokenizerState::AFTER_DOCTYPE_SYSTEM_IDENTIFIER
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the current DOCTYPE token's system identifier.
                        $doctypeToken->systemIdentifier .= self::REPLACEMENT_CHAR;
                    } elseif ($c === '>') {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the data state. Emit that DOCTYPE token.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append the current input character to the current
                        // DOCTYPE token's system identifier.
                        $doctypeToken->systemIdentifier .= $c;
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#doctype-system-identifier-(single-quoted)-state
                case TokenizerState::DOCTYPE_SYSTEM_IDENTIFIER_SINGLE_QUOTED:
                    $c = $this->inputStream->get();

                    if ($c === '\'') {
                        // Switch to the after DOCTYPE system identifier state.
                        $this->parser->setTokenizerState(
                            TokenizerState::AFTER_DOCTYPE_SYSTEM_IDENTIFIER
                        );
                    } elseif ($c === "\0") {
                        // Parse error.
                        // Append a U+FFFD REPLACEMENT CHARACTER character to
                        // the current DOCTYPE token's system identifier.
                        $doctypeToken->systemIdentifier .= self::REPLACEMENT_CHAR;
                    } elseif ($c === '>') {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on.
                        // Switch to the data state. Emit that DOCTYPE token.
                        $doctypeToken->setQuirksMode('on');
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        $doctypeToken->setQuirksMode('on');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Append the current input character to the current
                        // DOCTYPE token's system identifier.
                        $doctypeToken->systemIdentifier .= $c;
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#after-doctype-system-identifier-state
                case TokenizerState::AFTER_DOCTYPE_SYSTEM_IDENTIFIER:
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20"
                    ) {
                        // Ignore the character.
                    } elseif ($c === '>') {
                        // Switch to the data state. Emit the current DOCTYPE
                        // token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Set the DOCTYPE token's force-quirks flag to on. Emit
                        // that DOCTYPE token. Reconsume in the data state.
                        $doctypeToken->setQuirksMode('');
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Parse error.
                        // Switch to the bogus DOCTYPE state. (This does not set
                        // the DOCTYPE token's force-quirks flag to on.)
                        $this->parser->setTokenizerState(
                            TokenizerState::BOGUS_DOCTYPE
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#bogus-doctype-state
                case TokenizerState::BOGUS_DOCTYPE:
                    $c = $this->inputStream->get();

                    if ($c === '>') {
                        // Switch to the data state. Emit the DOCTYPE token.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                        yield $doctypeToken;
                    } elseif ($this->inputStream->isEoS()) {
                        // Emit the DOCTYPE token. Reconsume in the data state.
                        yield $doctypeToken;
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Ignore the character.
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#cdata-section-state
                case TokenizerState::CDATA_SECTION:
                    $c = $this->inputStream->get();

                    if ($c === ']') {
                        // Switch to the CDATA section bracket state.
                        $this->parser->setTokenizerState(
                            TokenizerState::CDATA_SECTION_BRACKET
                        );
                    } elseif ($this->inputStream->isEoS()) {
                        // Parse error.
                        // Reconsume in the data state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Emit the current input character as a character token.
                        // NOTE: U+0000 NULL characters are handled in the tree
                        // construction stage, as part of the in foreign content
                        // insertion mode, which is the only place where CDATA
                        // sections can appear.
                        yield new CharacterToken($c);
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#cdata-section-bracket-state
                case TokenizerState::CDATA_SECTION_BRACKET:
                    $c = $this->inputStream->get();

                    if ($c === ']') {
                        // Switch to the CDATA section end state.
                        $this->parser->setTokenizerState(
                            TokenizerState::CDATA_SECTION_END
                        );
                    } else {
                        // Emit a U+005D RIGHT SQUARE BRACKET character token.
                        // Reconsume in the CDATA section state.
                        yield new CharacterToken(']');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::CDATA_SECTION
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#cdata-section-end-state
                case TokenizerState::CDATA_SECTION_END:
                    $c = $this->inputStream->get();

                    if ($c === ']') {
                        // Emit a U+005D RIGHT SQUARE BRACKET character token.
                        yield new CharacterToken(']');
                    } elseif ($c === '>') {
                        // Switch to the data state.
                        $this->parser->setTokenizerState(TokenizerState::DATA);
                    } else {
                        // Emit two U+005D RIGHT SQUARE BRACKET character
                        // tokens. Reconsume in the CDATA section state.
                        yield new CharacterToken(']');
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::CDATA_SECTION
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#character-reference-state
                case TokenizerState::CHARACTER_REFERENCE:
                    // Set the temporary buffer to the empty string. Append a
                    // U+0026 AMPERSAND (&) character to the temporary buffer.
                    $buffer = '';
                    $buffer .= '&';
                    $c = $this->inputStream->get();

                    if ($c === "\x09" ||
                        $c === "\x0A" ||
                        $c === "\x0C" ||
                        $c === "\x20" ||
                        $c === '<' ||
                        $c === '&' ||
                        $this->inputStream->isEoS()
                    ) {
                        // Reconsume in the character reference end state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::CHARACTER_REFERENCE_END
                        );
                    } elseif ($c === '#') {
                        // Append the current input character to the temporary
                        // buffer. Switch to the numeric character reference
                        // state.
                        $buffer .= $c;
                        $this->parser->setTokenizerState(
                            TokenizerState::NUMERIC_CHARACTER_REFERENCE
                        );
                    } else {
                        $consumed = 0;
                        $found = false;
                        $i = 0;
                        $lastConsumed = '';
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );

                        if (!self::$entities) {
                            self::$entities = json_decode(
                                file_get_contents(self::ENTITY_PATH),
                                true
                            );
                        }

                        $prevLen = 0;

                        // Consume the maximum number of characters possible,
                        // with the consumed characters matching one of the
                        // identifiers in the first column of the named
                        // character references table (in a case-sensitive
                        // manner). Append each character to the temporary
                        // buffer when it's consumed.
                        foreach (self::UNIQUE_ENTITY_LENGTHS as $len) {
                            $lastConsumed = $this->inputStream->get(
                                $len - $prevLen
                            );
                            $buffer .= $lastConsumed;

                            if (isset(self::$entities[$buffer])) {
                                if ($lastConsumed === ';' ||
                                    ($lastConsumed !== ';' &&
                                    $this->inputStream->peek() !== ';')
                                ) {
                                    $found = true;
                                    break;
                                }
                            }

                            $prevLen = $len;
                        }

                        // If no match can be made and the temporary buffer
                        // consists of a U+0026 AMPERSAND character (&) followed
                        // by a sequence of one or more alphanumeric ASCII
                        // characters and a U+003B SEMICOLON character (;), then
                        // this is a parse error.
                        if (!$found &&
                            preg_match('/^&[A-Za-z0-9]+;$/', $buffer)
                        ) {
                            // Parse error.
                        }

                        // If no match can be made, switch to the character
                        // reference end state.
                        if (!$found) {
                            $this->parser->setTokenizerState(
                                TokenizerState::CHARACTER_REFERENCE_END
                            );
                        }

                        // If the character reference was consumed as part of an
                        // attribute (return state is either attribute value
                        // (double-quoted) state, attribute value
                        // (single-quoted) state or attribute value (unquoted)
                        // state), and the last character matched is not a
                        // U+003B SEMICOLON character (;), and the next input
                        // character is either a U+003D EQUALS SIGN character
                        // (=) or an alphanumeric ASCII character, then, for
                        // historical reasons, switch to the character reference
                        // end state.
                        switch ($returnState) {
                            case TokenizerState::ATTRIBUTE_VALUE_DOUBLE_QUOTED:
                            case TokenizerState::ATTRIBUTE_VALUE_SINGLE_QUOTED:
                            case TokenizerState::ATTRIBUTE_VALUE_UNQUOTED:
                                $next = $this->inputStream->peek();

                                if ($lastConsumed !== ';' &&
                                    ($next === '=' || ctype_alnum($next))
                                ) {
                                    $this->parser->setTokenizerState(
                                        TokenizerState::CHARACTER_REFERENCE_END
                                    );
                                }
                        }

                        // If the last character matched is not a U+003B
                        // SEMICOLON character (;), this is a parse error.
                        if ($lastConsumed !== ';') {
                            // Parse error.
                        }

                        // Set the temporary buffer to the empty string. Append
                        // one or two characters corresponding to the character
                        // reference name (as given by the second column of the
                        // named character references table) to the temporary
                        // buffer.
                        $codepoints = self::$entities[$buffer]['codepoints'];
                        $buffer = '';


                        foreach ($codepoints as $codepoint) {
                            $buffer .= pack('H*', dechex($codepoint));
                            //$buffer .= EncodingUtils::mb_chr($codepoint);
                        }

                        // Switch to the character reference end state.
                        $this->parser->setTokenizerState(
                            TokenizerState::CHARACTER_REFERENCE_END
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#numeric-character-reference-state
                case TokenizerState::NUMERIC_CHARACTER_REFERENCE:
                    $characterReferenceCode = 0;
                    $c = $this->inputStream->get();

                    if ($c === 'x' ||$c === 'X') {
                        // Append the current input character to the temporary
                        // buffer. Switch to the hexademical character reference
                        // start state.
                        $buffer .= $c;
                        $this->parser->setTokenizerState(
                            TokenizerState::HEXADECIMAL_CHARACTER_REFERENCE_START
                        );
                    } else {
                        // Reconsume in the decimal character reference start
                        // state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::DECIMAL_CHARACTER_REFERENCE_START
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#hexademical-character-reference-start-state
                case TokenizerState::HEXADECIMAL_CHARACTER_REFERENCE_START:
                    $c = $this->inputStream->get();

                    if (ctype_xdigit($c)) {
                        // Reconsume in the hexademical character reference
                        // state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::HEXADECIMAL_CHARACTER_REFERENCE
                        );
                    } else {
                        // Parse error.
                        // Reconsume in the character reference end state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::CHARACTER_REFERENCE_END
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#decimal-character-reference-start-state
                case TokenizerState::DECIMAL_CHARACTER_REFERENCE_START:
                    $c = $this->inputStream->get();

                    if (ctype_digit($c)) {
                        // Reconsume in the decimal character reference state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::DECIMAL_CHARACTER_REFERENCE
                        );
                    } else {
                        // Parse error.
                        // Reconsume in the character reference end state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::CHARACTER_REFERENCE_END
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#hexademical-character-reference-state
                case TokenizerState::HEXADECIMAL_CHARACTER_REFERENCE:
                    $c = $this->inputStream->get();

                    if (ctype_upper($c)) {
                        // Multiply the character reference code by 16. Add a
                        // numeric version of the current input character as a
                        // hexademical digit (subtract 0x0037 from the
                        // character's code point) to the character reference
                        // code.
                        $characterReferenceCode *= 16;
                        $characterReferenceCode += intval($c, 16);
                    } elseif (ctype_lower($c)) {
                        // Multiply the character reference code by 16. Add a
                        // numeric version of the current input character as a
                        // hexademical digit (subtract 0x0057 from the
                        // character's code point) to the character reference
                        // code.
                        $characterReferenceCode *= 16;
                        $characterReferenceCode += intval($c, 16);
                    } elseif (ctype_digit($c)) {
                        // Multiply the character reference code by 16.
                        // Add a numeric version of the current input character
                        // (subtract 0x0030 from the character's code point) to
                        // the character reference code.
                        $characterReferenceCode *= 16;
                        $characterReferenceCode += intval($c, 16);
                    } elseif ($c === ';') {
                        // Switch to the numeric character reference end state.
                        $this->parser->setTokenizerState(
                            TokenizerState::NUMERIC_CHARACTER_REFERENCE_END
                        );
                    } else {
                        // Parse error.
                        // Reconsume in the numeric character reference end
                        // state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::NUMERIC_CHARACTER_REFERENCE_END
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#decimal-character-reference-state
                case TokenizerState::DECIMAL_CHARACTER_REFERENCE:
                    $c = $this->inputStream->get();

                    if (ctype_digit($c)) {
                        // Multiply the character reference code by 10. Add a
                        // numeric version of the current input character
                        // (subtract 0x0030 from the character's code point) to
                        // the character reference code.
                        $characterReferenceCode *= 10;
                        $characterReferenceCode += (int) $c;
                    } elseif ($c === ';') {
                        // Switch to the numeric character reference end state.
                        $this->parser->setTokenizerState(
                            TokenizerState::NUMERIC_CHARACTER_REFERENCE_END
                        );
                    } else {
                        // Parse error.
                        // Reconsume in the numeric character reference end
                        // state.
                        $this->inputStream->seek(
                            -1,
                            CodePointStream::SEEK_RELATIVE
                        );
                        $this->parser->setTokenizerState(
                            TokenizerState::NUMERIC_CHARACTER_REFERENCE_END
                        );
                    }

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#numeric-character-reference-end-state
                case TokenizerState::NUMERIC_CHARACTER_REFERENCE_END:
                    // Can't use isset() here because PHP < 7 thinks it is
                    // operating on an expression.
                    $mapCode = array_key_exists(
                        $characterReferenceCode,
                        self::CHARACTER_REFERENCE_MAP
                    );

                    // If that number is one of the numbers in the first column
                    // of the following table, then this is a parse error. Find
                    // the row with that number in the first column, and set the
                    // character reference code to the number in the second
                    // column of that row.
                    if ($mapCode) {
                        $characterReferenceCode = self::CHARACTER_REFERENCE_MAP[
                            $characterReferenceCode
                        ];
                    }

                    // If the number is in the range 0xD800 to 0xDFFF or is
                    // greater than 0x10FFFF, then this is a parse error. Set
                    // the character reference code to 0xFFFD.
                    if (($characterReferenceCode >= 0xD800 &&
                        $characterReferenceCode <= 0xDFFF) ||
                        $characterReferenceCode > 0x10FFF
                    ) {
                        $characterReferenceCode = 0xFFFD;
                    } elseif (($characterReferenceCode >= 0x0001 &&
                        $characterReferenceCode <= 0x0008) ||
                        ($characterReferenceCode >= 0x000D &&
                            $characterReferenceCode <= 0x001F) ||
                        ($characterReferenceCode >= 0x007F &&
                            $characterReferenceCode <= 0x009F) ||
                        ($characterReferenceCode >= 0xFDD0 &&
                            $characterReferenceCode <= 0xFDEF) ||
                        ($characterReferenceCode == 0xFFFE ||
                            $characterReferenceCode == 0xFFFF ||
                            $characterReferenceCode == 0x1FFFE ||
                            $characterReferenceCode == 0x1FFFF ||
                            $characterReferenceCode == 0x2FFFE ||
                            $characterReferenceCode == 0x2FFFF ||
                            $characterReferenceCode == 0x3FFFE ||
                            $characterReferenceCode == 0x3FFFF ||
                            $characterReferenceCode == 0x4FFFE ||
                            $characterReferenceCode == 0x4FFFF ||
                            $characterReferenceCode == 0x5FFFE ||
                            $characterReferenceCode == 0x5FFFF ||
                            $characterReferenceCode == 0x6FFFE ||
                            $characterReferenceCode == 0x6FFFF ||
                            $characterReferenceCode == 0x7FFFE ||
                            $characterReferenceCode == 0x7FFFF ||
                            $characterReferenceCode == 0x8FFFE ||
                            $characterReferenceCode == 0x8FFFF ||
                            $characterReferenceCode == 0x9FFFE ||
                            $characterReferenceCode == 0x9FFFF ||
                            $characterReferenceCode == 0xAFFFE ||
                            $characterReferenceCode == 0xAFFFF ||
                            $characterReferenceCode == 0xBFFFE ||
                            $characterReferenceCode == 0xBFFFF ||
                            $characterReferenceCode == 0xCFFFE ||
                            $characterReferenceCode == 0xCFFFF ||
                            $characterReferenceCode == 0xDFFFE ||
                            $characterReferenceCode == 0xDFFFF ||
                            $characterReferenceCode == 0xEFFFE ||
                            $characterReferenceCode == 0xEFFFF ||
                            $characterReferenceCode == 0xFFFFE ||
                            $characterReferenceCode == 0xFFFFF ||
                            $characterReferenceCode == 0x10FFFE ||
                            $characterReferenceCode == 0x10FFFF)
                    ) {
                        // Parse error.
                    }

                    // Set the temporary buffer to the empty string. Append the
                    // Unicode character with code point equal to the character
                    // reference code to the temporary buffer. Switch to the
                    // character reference end state.
                    $buffer = '';
                    $buffer .= EncodingUtils::mb_chr($characterReferenceCode);
                    $this->parser->setTokenizerState(
                        TokenizerState::CHARACTER_REFERENCE_END
                    );

                    break;

                // https://html.spec.whatwg.org/multipage/syntax.html#character-reference-end-state
                case TokenizerState::CHARACTER_REFERENCE_END:
                    $c = $this->inputStream->get();

                    switch ($returnState) {
                        case TokenizerState::ATTRIBUTE_VALUE_DOUBLE_QUOTED:
                        case TokenizerState::ATTRIBUTE_VALUE_SINGLE_QUOTED:
                        case TokenizerState::ATTRIBUTE_VALUE_UNQUOTED:
                            // Append each character in the temporary buffer
                            // (in the order they were added to the buffer) to
                            // the current attribute's value.
                            $attributeToken->value .= $buffer;

                            break;

                        default:
                            $bufferStream = new CodePointStream($buffer);

                            // For each of the characters in the temporary
                            // buffer (in the order they were added to the
                            // buffer), emit the character as a character token.
                            while (!$bufferStream->isEoS()) {
                                yield new CharacterToken($bufferStream->get());
                            }
                    }

                    $this->inputStream->seek(
                        -1,
                        CodePointStream::SEEK_RELATIVE
                    );
                    $this->parser->setTokenizerState($returnState);

                    break;
            }
        } while (!$this->inputStream->isEoS());
    }

    /**
     * @see https://html.spec.whatwg.org/#appropriate-end-tag-token
     *
     * @return bool
     */
    protected function isAppropriateEndTag(EndTagToken $aEndTag)
    {
        return $this->lastEmittedStartTagToken &&
            $this->lastEmittedStartTagToken->tagName === $aEndTag->tagName;
    }

    public function setLastEmittedStartTagToken(StartTagToken $aToken)
    {
        $this->lastEmittedStartTagToken = $aToken;
    }

    protected function emit(...$aTokens)
    {
        if ($aTokens[0] instanceof StartTagToken) {
            $this->lastEmittedStartTagToken = $aTokens[0];
        } elseif ($aTokens[0] instanceof EndTagToken) {
            // When an end tag token is emitted with attributes, that is a
            // parse error.
            if (!$aTokens[0]->attributes->isEmpty()) {
                // Parse error.
            }

            // When an end tag token is emitted with its self-closing flag
            // set, that is a parse error.
            if ($aTokens[0]->isSelfClosing()) {
                // Parse error.
            }
        }

        // Do some epic tree building.
        //$this->treeBuilder->run(...$aTokens);
        yield $aTokens;

        // When a start tag token is emitted with its self-closing flag set, if
        // the flag is not acknowledged when it is processed by the tree
        // construction stage, that is a parse error.
        if ($aTokens[0] instanceof StartTagToken &&
            !$aTokens[0]->wasAcknowledged()
        ) {
            // Parse error.
        }
    }
}
