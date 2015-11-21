<?php
class URLParser {
    const SCHEME_START_STATE = 1;
    const SCHEME_STATE = 2;
    const NO_SCHEME_STATE = 3;
    const SPECIAL_RELATIVE_OR_AUTHORITY_STATE = 4;
    const PATH_OR_AUTHORITY_STATE = 5;
    const RELATIVE_STATE = 6;
    const RELATIVE_SLASH_STATE = 7;
    const SPECIAL_AUTHORITY_SLASHES_STATE = 8;
    const SPECIAL_AUTHORITY_IGNORE_SLASHES_STATE = 9;
    const AUTHORITY_STATE = 10;
    const HOST_STATE = 11;
    const HOSTNAME_STATE = 12;
    const PORT_STATE = 13;
    const FILE_STATE = 14;
    const FILE_SLASH_STATE = 15;
    const FILE_HOST_STATE = 16;
    const PATH_START_STATE = 17;
    const PATH_STATE = 18;
    const NON_RELATIVE_PATH_STATE = 19;
    const QUERY_STATE = 20;
    const FRAGMENT_STATE = 21;

    const REGEX_C0_CONTROLS = '/[\x{0000}-\x{001F}]/';
    const REGEX_ASCII_DIGITS = '/[\x{0030}-\x{0039}]/';
    const REGEX_ASCII_HEX_DIGITS = '/^[\x{0030}-\x{0039}\x{0041}-\x{0046}\x{0061}-\x{0066}]{2}/';
    const REGEX_ASCII_HEX_DIGIT = '/[\x{0030}-\x{0039}\x{0041}-\x{0046}\x{0061}-\x{0066}]/';
    const REGEX_ASCII_ALPHA = '/[\x{0041}-\x{005A}\x{0061}-\x{007A}]/';
    const REGEX_ASCII_ALPHANUMERIC = '/[\x{0030}-\x{0039}\x{0041}-\x{005A}\x{0061}-\x{007A}]/';
    const REGEX_URL_CODE_POINTS = '/[\x{0030}-\x{0039}\x{0041}-\x{005A}\x{0061}-\x{007A}
            !$&\'()*+,\-.\/:;=?@_~
            \x{00A0}-\x{D7DD}
            \x{E000}-\x{FDCF}
            \x{FDF0}-\x{FFFD}
            \x{10000}-\x{1FFFD}
            \x{20000}-\x{2FFFD}
            \x{30000}-\x{3FFFD}
            \x{40000}-\x{4FFFD}
            \x{50000}-\x{5FFFD}
            \x{60000}-\x{6FFFD}
            \x{70000}-\x{7FFFD}
            \x{80000}-\x{8FFFD}
            \x{90000}-\x{9FFFD}
            \x{A0000}-\x{AFFFD}
            \x{B0000}-\x{BFFFD}
            \x{C0000}-\x{CFFFD}
            \x{D0000}-\x{DFFFD}
            \x{E0000}-\x{EFFFD}
            \x{F0000}-\x{FFFFD}
            \x{100000}-\x{10FFFD}
             ]/u';
    const REGEX_ASCII_WHITESPACE = '/[\x{0009}\x{000A}\x{000D}]/';
    const REGEX_ASCII_DOMAIN = '/[\x{0000}\x{0009}\x{000A}\x{000D}\x{0020}#%\/:?@[\\\]]/';
    const REGEX_WINDOWS_DRIVE_LETTER = '/[\x{0041}-\x{005A}\x{0061}-\x{007A}][:|]/';
    const REGEX_NORMALIZED_WINDOWS_DRIVE_LETTER = '/[\x{0041}-\x{005A}\x{0061}-\x{007A}]:/';

    const ENCODE_SET_SIMPLE = 1;
    const ENCODE_SET_DEFAULT = 2;
    const ENCODE_SET_USERINFO = 3;

    public static $specialSchemes = array('ftp' => 21,
                                        'file' => '',
                                        'gopher' => 70,
                                        'http' => 80,
                                        'https' => 443,
                                        'ws' => 80,
                                        'wss' => 443);

    public static $singleDotPathSegment = array('.' => '.',
                                                '%2e', '.');
    public static $doubleDotPathSegment = array('..' => '..',
                                                '.%2e' => '..',
                                                '%2e.' => '..',
                                                '%2e%2e' => '..');

    public function __construct() {
    }

    public static function URLParser($aInput, URL $aBase = null, $aEncoding = null) {
        $url = self::basicURLParser($aInput, $aBase, $aEncoding);

        if ($url === false) {
            return false;
        }

        if ($url->mScheme != 'blob') {
            return $url;
        }

        /*if ($url->mSchemeData != blob store) {
            return $url;
        }

        $url->object = structured clone;*/

        return $url;
    }

    /**
     * Parses a string as a URL.  The string can be an absolute URL or a relative URL.  If a relative URL is give,
     * a base URL must also be given so that a complete URL can be resolved.  It can also parse individual parts of a URL
     * when the state machine starts in a specific state.
     *
     * @link https://url.spec.whatwg.org/#concept-basic-url-parser
     *
     * @param  string   $aInput    The URL string that is to be parsed.
     *
     * @param  URL|null $aBaseUrl  Optional argument that is only needed if the input is a relative URL.  This represents the base URL,
     *                             which in most cases, is the document's URL, it may also be a node's base URI or whatever base URL you
     *                             wish to resolve relative URLs against. Default is null.
     *
     * @param  string   $aEncoding Optional argument that overrides the default encoding.  Default is UTF-8.
     *
     * @param  URL|null $aUrl      Optional argument.  This represents an existing URL object that should be modified based on the input
     *                             URL and optional base URL.  Default is null.
     *
     * @param  int      $aState    Optional argument. An integer that determines what state the state machine will begin parsing the
     *                             input URL from.  Suppling a value for this parameter will override the default state of SCHEME_START_STATE.
     *                             Default is null.
     *
     * @return URL|bool            Returns a URL object upon successfully parsing the input or false if parsing input failed.
     */
    public static function basicURLParser($aInput, URL $aBaseUrl = null, $aEncoding = null, URL $aUrl = null, $aState = null) {
        if ($aUrl) {
            $url = $aUrl;
            $input = $aInput;
        } else {
            $url = new URL();
            $input = trim($aInput);
        }

        $state = $aState ? $aState : self::SCHEME_START_STATE;
        $base = $aBaseUrl;
        $encoding = $aEncoding ? $aEncoding : 'utf-8';
        $buffer = '';

        for ($pointer = 0; $pointer <= mb_strlen($input, $encoding); $pointer++) {
            $c = mb_substr($input, $pointer, 1, $encoding);

            switch ($state) {
                case self::SCHEME_START_STATE:
                    if (preg_match(self::REGEX_ASCII_ALPHA, $c)) {
                        $buffer .= strtolower($c);
                        $state = self::SCHEME_STATE;
                    } elseif (!$aState) {
                        $state = self::NO_SCHEME_STATE;
                        $pointer--;
                    } else {
                        // Syntax violation. Terminate this algorithm.
                        break;
                    }

                    break;

                case self::SCHEME_STATE:
                    if (preg_match(self::REGEX_ASCII_ALPHANUMERIC, $c) || preg_match('/[+\-.]/', $c)) {
                        $buffer .= strtolower($c);
                    } elseif ($c == ':') {
                        if ($aState) {
                            $bufferIsSpecialScheme = false;

                            foreach (self::$specialSchemes as $scheme => $port) {
                                if (stripos($scheme, $buffer) !== 0) {
                                    $bufferIsSpecialScheme = true;
                                    break;
                                }
                            }

                            if (($url->_isSpecial() && !$bufferIsSpecialScheme) ||
                                (!$url->_isSpecial() && $bufferIsSpecialScheme)) {
                                // Terminate this algorithm.
                                break;
                            }
                        }

                        $url->mScheme = $buffer;
                        $buffer = '';

                        if ($aState) {
                            // Terminate this algoritm
                            break;
                        }

                        $offset = $pointer + 1;

                        if ($url->mScheme == 'file') {
                            if (mb_strpos($input, '//', $offset, $encoding) == $offset) {
                                // Syntax violation
                            }

                            $state = self::FILE_STATE;
                        } elseif ($url->_isSpecial() && $base && $base->mScheme == $url->mScheme) {
                            $state = self::SPECIAL_RELATIVE_OR_AUTHORITY_STATE;
                        } elseif ($url->_isSpecial()) {
                            $state = self::SPECIAL_AUTHORITY_SLASHES_STATE;
                        } else if (mb_strpos($input, '/', $offset, $encoding) == $offset) {
                            $state = self::PATH_OR_AUTHORITY_STATE;
                        } else {
                            $url->mFlags |= URL::FLAG_NON_RELATIVE;
                            $url->mPath->push('');
                            $state = self::NON_RELATIVE_PATH_STATE;
                        }
                    } elseif (!$aState) {
                        $buffer = '';
                        $state = self::NO_SCHEME_STATE;

                        // Reset the pointer to poing at the first code point.  The pointer needs to be set to -1 to compensate for the
                        // loop incrementing pointer after this iteration.
                        $pointer = -1;
                    } else {
                        // Syntax violation. Terminate this algorithm.
                        break;
                    }

                    break;

                case self::NO_SCHEME_STATE:
                    if (!$base || ($base->mFlags & URL::FLAG_NON_RELATIVE && $c != '#')) {
                        // Syntax violation. Return failure
                        return false;
                    } else if ($base->mFlags & URL::FLAG_NON_RELATIVE && $c == '#') {
                        $url->mScheme = $base->mScheme;
                        $url->mPath = clone $base->mPath;
                        $url->mQuery = $base->mQuery;
                        $url->mFragment = '';
                        $url->mFlags |= URL::FLAG_NON_RELATIVE;
                        $state = self::FRAGMENT_STATE;
                    } else if ($base->mScheme != 'file') {
                        $state = self::RELATIVE_STATE;
                        $pointer--;
                    } else {
                        $state = self::FILE_STATE;
                        $pointer--;
                    }

                    break;

                case self::SPECIAL_RELATIVE_OR_AUTHORITY_STATE:
                    $offset = $pointer + 1;

                    if ($c == '/' && mb_strpos($input, '/', $offset, $encoding) == $offset) {
                        $state = self::SPECIAL_AUTHORITY_IGNORE_SLASHES_STATE;
                        $pointer++;
                    } else {
                        // Syntax violation
                        $state = self::RELATIVE_STATE;
                        $pointer--;
                    }

                    break;

                case self::PATH_OR_AUTHORITY_STATE:
                    if ($c == '/') {
                        $state = self::AUTHORITY_STATE;
                    } else {
                        $state = self::PATH_STATE;
                        $pointer--;
                    }

                    break;

                case self::RELATIVE_STATE:
                    $url->mScheme = $base->mScheme;

                    if ($c === ''/* EOF */) {
                        $url->mUsername = $base->mUsername;
                        $url->mPassword = $base->mPassword;
                        $url->mHost = $base->mHost;
                        $url->mPort = $base->mPort;
                        $url->mPath = clone $base->mPath;
                        $url->mQuery = $base->mQuery;
                    } else if ($c == '/') {
                        $state = self::RELATIVE_SLASH_STATE;
                    } else if ($c == '?') {
                        $url->mUsername = $base->mUsername;
                        $url->mPassword = $base->mPassword;
                        $url->mHost = $base->mHost;
                        $url->mPort = $base->mPort;
                        $url->mPath = clone $base->mPath;
                        $url->mQuery = '';
                        $state = self::QUERY_STATE;
                    } else if ($c == '#') {
                        $url->mUsername = $base->mUsername;
                        $url->mPassword = $base->mPassword;
                        $url->mHost = $base->mHost;
                        $url->mPort = $base->mPort;
                        $url->mPath = clone $base->mPath;
                        $url->mQuery = $base->mQuery;
                        $url->mFragment = '';
                    } else {
                        if ($url->_isSpecial() && $c == '/') {
                            // Syntax violation
                            $state = self::RELATIVE_SLASH_STATE;
                        } else {
                            $url->mUsername = $base->mUsername;
                            $url->mPassword = $base->mPassword;
                            $url->mHost = $base->mHost;
                            $url->mPort = $base->mPort;
                            $url->mPath = clone $base->mPath;

                            if (!$url->mPath->isEmpty()) {
                                $url->mPath->pop();
                            }

                            $state = self::PATH_STATE;
                            $pointer--;
                        }
                    }

                    break;

                case self::RELATIVE_SLASH_STATE:
                    if ($c == '/' || ($url->_isSpecial() && $c == '\\')) {
                        if ($c == '\\') {
                            // Syntax violation
                        }

                        $state = self::SPECIAL_AUTHORITY_IGNORE_SLASHES_STATE;
                    } else {
                        $url->mUsername = $base->mUsername;
                        $url->mPassword = $base->mPassword;
                        $url->mHost = $base->mHost;
                        $url->mPort = $base->mPort;
                        $state = self::PATH_STATE;
                        $pointer--;
                    }

                    break;

                case self::SPECIAL_AUTHORITY_SLASHES_STATE:
                    $offset = $pointer + 1;

                    if ($c == '/' && mb_strpos($input, '/', $offset, $encoding) == $offset) {
                        $state = self::SPECIAL_AUTHORITY_IGNORE_SLASHES_STATE;
                        $pointer++;
                    } else {
                        // Syntax violation
                        $state = self::SPECIAL_AUTHORITY_IGNORE_SLASHES_STATE;
                        $pointer--;
                    }

                    break;

                case self::SPECIAL_AUTHORITY_IGNORE_SLASHES_STATE:
                    if ($c != '/' && $c != '\\') {
                        $state = self::AUTHORITY_STATE;
                        $pointer--;
                    } else {
                        // Syntax violation
                    }

                    break;

                case self::AUTHORITY_STATE:
                    if ($c == '@') {
                        // Syntax violation

                        if ($url->mFlags & URL::FLAG_AT) {
                            $buffer .= '%40';
                        }

                        $url->mFlags |= URL::FLAG_AT;

                        for ($i = 0; $i < mb_strlen($buffer, $encoding); $i++) {
                            $codePoint = mb_substr($buffer, $i, 1, $encoding);

                            if (preg_match(self::REGEX_ASCII_WHITESPACE, $codePoint)) {
                                continue;
                            }

                            if ($codePoint == ':' && $url->mPassword === null) {
                                $url->mPassword = '';
                                continue;
                            }

                            $encodedCodePoints = self::utf8PercentEncode($codePoint, self::ENCODE_SET_USERINFO);

                            if ($url->mPassword !== null) {
                                $url->mPassword .= $encodedCodePoints;
                            } else {
                                $url->mUsername .= $encodedCodePoints;
                            }
                        }

                        $buffer = '';
                    } else if (($c === ''/* EOF */ || $c == '/' || $c == '?' || $c == '#') || ($url->_isSpecial() && $c == '\\')) {
                        $pointer -= mb_strlen($buffer, $encoding) + 1;
                        $buffer = '';
                        $state = self::HOST_STATE;
                    } else {
                        $buffer .= $c;
                    }

                    break;

                case self::HOST_STATE:
                case self::HOSTNAME_STATE:
                    if ($c == ':' && !($url->mFlags & URL::FLAG_ARRAY)) {
                        if ($url->_isSpecial() && !$buffer) {
                            // Return failure
                            return false;
                        }

                        $host = self::parseHost($buffer);

                        if ($host === false) {
                            // Return failure
                            return false;
                        }

                        $url->mHost = $host;
                        $buffer = '';
                        $state = self::PORT_STATE;

                        if ($aState == self::HOSTNAME_STATE) {
                            // Terminate this algorithm
                            break;
                        }
                    } else if (($c === ''/* EOF */ || $c == '/' || $c == '?' || $c == '#') || ($url->_isSpecial() && $c == '\\')) {
                        $pointer--;

                        if ($url->_isSpecial() && !$buffer) {
                            // Return failure
                            return false;
                        }

                        $host = self::parseHost($buffer);

                        if ($host === false) {
                            // Return failure
                            return false;
                        }

                        $url->mHost = $host;
                        $buffer = '';
                        $state = self::PATH_START_STATE;

                        if ($aState) {
                            // Terminate this algorithm
                            break;
                        }
                    } else if (preg_match(self::REGEX_ASCII_WHITESPACE, $c)) {
                        // Syntax violation
                    } else {
                        if ($c == '[') {
                            $url->mFlags |= URL::FLAG_ARRAY;
                        } else if ($c == ']') {
                            $url->mFlags &= ~URL::FLAG_ARRAY;
                        } else {
                            $buffer .= $c;
                        }
                    }

                    break;

                case self::PORT_STATE:
                    if (preg_match(self::REGEX_ASCII_DIGITS, $c)) {
                        $buffer .= $c;
                    } else if (($c === ''/* EOF */ || $c == '/' || $c == '?' || $c == '#') || ($url->_isSpecial() && $c == '\\') || $aState) {
                        if ($buffer) {
                            $port = intval($buffer, 10);

                            if ($port > pow(2, 16) - 1) {
                                // Syntax violation. Return failure.
                                return false;
                            }

                            if (array_key_exists($url->mScheme, self::$specialSchemes) && self::$specialSchemes[$url->mScheme] == $port) {
                                $url->mPort = null;
                            } else {
                                $url->mPort = $port;
                            }

                            $buffer = '';
                        }

                        if ($aState) {
                            // Terminate this algorithm
                            break;
                        }

                        $state = self::PATH_START_STATE;
                        $pointer--;
                    } else if (preg_match(self::REGEX_ASCII_WHITESPACE, $c)) {
                        // Syntax violation
                    } else {
                        // Syntax violation. Return failure.
                        return false;
                    }

                    break;

                case self::FILE_STATE:
                    if ($url->mScheme == 'file') {
                        if ($c === ''/* EOF */) {
                            if ($base && $base->mScheme == 'file') {
                                $url->mHost = $base->mHost;
                                $url->mPath = clone $base->mPath;
                                $url->mQuery = $base->mQuery;
                            }
                        } else if ($c == '/' || $c == '\\') {
                            if ($c == '\\') {
                                // Syntax violation
                            }

                            $state = self::FILE_SLASH_STATE;
                        } else if ($c == '?') {
                            if ($base && $base->mScheme == 'file') {
                                $url->mHost = $base->mHost;
                                $url->mPath = clone $base->mPath;
                                $url->mQuery = '';
                                $state = self::QUERY_STATE;
                            }
                        } else if ($c == '#') {
                            if ($base && $base->mScheme == 'file') {
                                $url->mHost = $base->mHost;
                                $url->mPath = clone $base->mPath;
                                $url->mQuery = $base->mQuery;
                                $url->mFragment = $base->mFragment;
                                $state = self::FRAGMENT_STATE;
                            }
                        } else {
                            // Platform-independent Windows drive letter quirk
                            if ($base && $base->mScheme == 'file' && (
                                !preg_match(self::REGEX_WINDOWS_DRIVE_LETTER, mb_substr($input, $pointer, 2, $encoding)) ||
                                mb_strlen(mb_substr($input, $pointer, mb_strlen($input, $encoding), $encoding), $encoding) == 1 ||
                                !preg_match('/[/\\?#]/', mb_substr($input, $pointer + 2, 1, $encoding)))) {
                                $url->mHost = $base->mHost;
                                $url->mPath = clone $base->mPath;

                                if (!$url->mPath->isEmpty()) {
                                    $url->mPath->pop();
                                }
                            } else if ($base && $base->mScheme == 'file') {
                                // Syntax violation
                            } else {
                                $state = self::PATH_STATE;
                                $pointer--;
                            }
                        }
                    }

                    break;

                case self::FILE_SLASH_STATE:
                    if ($c == '/' || $c == '\\') {
                        if ($c == '\\') {
                            // Syntax violation
                        }

                        $state = self::FILE_HOST_STATE;
                    } else {
                        if ($base && $base->mScheme == 'file' && preg_match(self::REGEX_NORMALIZED_WINDOWS_DRIVE_LETTER, $base->mPath[0])) {
                            // This is a (platform-independent) Windows drive letter quirk. Both url’s and base’s
                            // host are null under these conditions and therefore not copied.
                            $url->mPath->push($base->mPath[0]);
                        }

                        $state = self::PATH_STATE;
                        $pointer--;
                    }

                    break;

                case self::FILE_HOST_STATE:
                    if ($c === ''/* EOF */ || $c == '/' || $c == '\\' || $c == '?' || $c == '#') {
                        $pointer--;


                        if (preg_match(self::REGEX_WINDOWS_DRIVE_LETTER, $buffer)) {
                            // This is a (platform-independent) Windows drive letter quirk. buffer is not reset here and instead used in the path state.
                            // Syntax violation
                            $state = self::PATH_STATE;
                        } else if (!$buffer) {
                            $state = self::PATH_START_STATE;
                        } else {
                            $host = self::parseHost($buffer);

                            if ($host === false) {
                                // Return failure
                                return false;
                            }

                            if ($host != 'localhost') {
                                $url->mHost = $host;
                            }

                            $buffer = '';
                            $state = self::PATH_START_STATE;
                        }
                    } else if (preg_match(self::REGEX_ASCII_WHITESPACE, $c)) {
                        // Syntax violation
                    } else {
                        $buffer .= $c;
                    }

                    break;

                case self::PATH_START_STATE:
                    if ($url->_isSpecial() && $c == '\\') {
                        // Syntax violation
                    }

                    $state = self::PATH_STATE;

                    if ($c != '/' && !($url->_isSpecial() && $c != '\\')) {
                        $pointer--;
                    }

                    break;

                case self::PATH_STATE:
                    if ($c === ''/* EOF */ || $c == '/' || ($url->_isSpecial() && $c == '\\') || (!$aState && ($c == '?' || $c == '#'))) {
                        if ($url->_isSpecial() && $c == '\\') {
                            // Syntax violation
                        }

                        if (in_array($buffer, self::$doubleDotPathSegment)) {
                            if (!$url->mPath->isEmpty()) {
                                $url->mPath->pop();
                            }

                            if ($c != '/' && !($url->_isSpecial() && $c == '\\')) {
                                $url->mPath->push('');
                            }
                        } else if (in_array($buffer, self::$singleDotPathSegment) && $c != '/' && !($url->_isSpecial() && $c == '\\')) {
                            $url->mPath->push('');
                        } else if (!in_array($buffer, self::$singleDotPathSegment)) {
                            if ($url->mScheme == 'file' && $url->mPath->isEmpty() && preg_match(self::REGEX_WINDOWS_DRIVE_LETTER, $buffer)) {
                                if ($url->mHost !== null) {
                                    // Syntax violation
                                }

                                $url->mHost = null;
                                // This is a (platform-independent) Windows drive letter quirk.
                                $buffer = mb_substr($buffer, 0, 1, $encoding) . ':';
                            }

                            $url->mPath->push($buffer);
                        }

                        $buffer = '';

                        if ($c == '?') {
                            $url->mQuery = '';
                            $state = self::QUERY_STATE;
                        } else if ($c == '#') {
                            $url->mFragment = '';
                            $state = self::FRAGMENT_STATE;
                        }
                    } else if (preg_match(self::REGEX_ASCII_WHITESPACE, $c)) {
                        // Syntax violation
                    } else {
                        if (!preg_match(self::REGEX_URL_CODE_POINTS, $c) && $c != '%') {
                            // Syntax violation
                        }

                        if ($c == '%' && preg_match(self::REGEX_ASCII_HEX_DIGITS, mb_substr($input, $pointer + 1, 2, $encoding))) {
                            // Syntax violation
                        }

                        $buffer .= self::utf8PercentEncode($c, self::ENCODE_SET_DEFAULT);
                    }

                    break;

                case self::NON_RELATIVE_PATH_STATE:
                    if ($c == '?') {
                        $url->mQuery = '';
                        $state = self::QUERY_STATE;
                    } else if ($c == '#') {
                        $url->mFragment = '';
                        $state = self::FRAGMENT_STATE;
                    } else {
                        if ($c !== ''/* EOF */ && !preg_match(self::REGEX_URL_CODE_POINTS, $c) && $c != '%') {
                            // Syntax violation
                        }

                        if ($c == '%' && preg_match(self::REGEX_ASCII_HEX_DIGITS, mb_substr($input, $pointer + 1, 2, $encoding))) {
                            // Syntax violation
                        }

                        if ($c !== ''/* EOF */ && !preg_match(self::REGEX_ASCII_WHITESPACE, $c)) {
                            if (!$url->mPath->isEmpty()) {
                                $url->mPath[0] .= self::utf8PercentEncode($c);
                            }
                        }
                    }

                    break;

                case self::QUERY_STATE:
                    if ($c === ''/* EOF */ || (!$aState && $c == '#')) {
                        if (!$url->_isSpecial() || $url->mScheme == 'ws' || $url->mScheme == 'wss') {
                            $encoding = 'utf-8';
                        }

                        $buffer = mb_convert_encoding($buffer, $encoding);

                        for ($i = 0; $i < strlen($buffer); $i++) {
                            $byteOrd = ord($buffer[$i]);

                            if ($byteOrd < 0x21 || $byteOrd > 0x7E || $byteOrd == 0x22 || $byteOrd == 0x23 || $byteOrd == 0x3C || $byteOrd == 0x3E) {
                                $url->mQuery .= self::percentEncode($buffer[$i]);
                            } else {
                                $url->mQuery .= $buffer[$i];
                            }
                        }

                        $buffer = '';

                        if ($c == '#') {
                            $url->mFragment = '';
                            $state = self::FRAGMENT_STATE;
                        }
                    } else if (preg_match(self::REGEX_ASCII_WHITESPACE, $c)) {
                        // Syntax violation
                    } else {
                        if (!preg_match(self::REGEX_URL_CODE_POINTS, $c) && $c != '%') {
                            // Syntax violation
                        }

                        if ($c == '%' && preg_match(self::REGEX_ASCII_HEX_DIGITS, mb_substr($input, $pointer + 1, 2, $encoding))) {
                            // Syntax violation
                        }

                        $buffer .= $c;
                    }

                    break;

                case self::FRAGMENT_STATE:
                    if ($c === ''/* EOF */) {
                        // Do nothing
                    } else if (preg_match(self::REGEX_ASCII_WHITESPACE, $c) || preg_match('/\x{0000}/', $c)) {
                        // Syntax violation
                    } else {
                        if (!preg_match(self::REGEX_URL_CODE_POINTS, $c) && $c != '%') {
                            // Syntax violation
                        }

                        if ($c == '%' && preg_match(self::REGEX_ASCII_HEX_DIGITS, mb_substr($input, $pointer + 1, 2, $encoding))) {
                            // Syntax violation
                        }

                        $url->mFragment .= $c;
                    }

                    break;
            }
        }

        return $url;
    }

    public static function parseHost($aInput, $aUnicodeFlag = null) {
        if ($aInput === '') {
            return false;
        }

        if ($aInput[0] == '[') {
            if ($aInput[strlen($aInput) - 1] != ']') {
                // parse error
                return false;
            }

            return self::IPv6Parser(substr($aInput, 1, strlen($aInput) - 2));
        }

        $domain = utf8_decode(self::percentDecode(self::encode($aInput)));
        $asciiDomain = URL::domainToASCII($domain);

        if ($asciiDomain === false) {
            return false;
        }

        if (preg_match(self::REGEX_ASCII_DOMAIN, $asciiDomain)) {
            return false;
        }

        return $aUnicodeFlag ? self::domainToUnicode($domain) : $asciiDomain;
    }

    public static function IPv6Parser($aInput) {
        $address = '0:0:0:0:0:0:0:0';
        $piecePointer = 0;
        $piece = substr($address, $piecePointer, 1);
        $compressPointer = null;
        $pointer = 0;
        $c = substr($aInput, $pointer, 1);

        if ($c == ':') {
            if (substr($aInput, $pointer + 1, 1) != ':') {
                // parse error
                return false;
            }

            $pointer += 2;
            $piecePointer++;
            $compressPointer = $piecePointer;
        }

        Main:
        while ($c !== false) {
            if ($piecePointer == 8) {
                // parse error
                return false;
            }

            if ($c == ':') {
                if ($compressPointer !== null) {
                    // parse error
                    return false;
                }

                $pointer++;
                $c = substr($aInput, $pointer, 1);
                $piecePointer++;
                $compressPointer = $piecePointer;
                goto Main;
            }

            $value = 0;
            $length = 0;

            while ($length < 4 && preg_match(self::REGEX_ASCII_HEX_DIGIT, $c)) {
                $value = bin2hex($value * 0x10 + $c);
                $pointer++;
                $length++;
                $c = substr($aInput, $pointer, 1);
            }

            if ($c == '.') {
                if ($length == 0) {
                    // parse error
                    return false;
                }

                $pointer -= $length;
                $c = substr($aInput, $pointer, 1);
                goto IPv4;
            } elseif ($c == ':') {
                $pointer++;
                $c = substr($aInput, $pointer, 1);

                if ($c === false) {
                    // parse error
                    return false;
                }
            } elseif ($c !== false) {
                // parse error
                return false;
            }

            $piece = $value;
            $piecePointer++;
        }

        if ($c === false) {
            goto Finale;
        }

        IPv4:
        if ($piecePointer > 6) {
            // parse error
            return false;
        }

        $dotsSeen = 0;

        while ($c !== false) {
            $value = null;

            if (!preg_match(self::REGEX_ASCII_HEX_DIGIT, $c)) {
                // parse error
                return false;
            }

            while (preg_match(self::REGEX_ASCII_HEX_DIGIT, $c)) {
                $number = (float) $c;

                if ($value === null) {
                    $value = $number;
                } elseif ($value === 0) {
                    // parse error
                    return false;
                } else {
                    $value = $value * 10 + $number;
                }

                $pointer++;
                $c = substr($aInput, $pointer, 1);

                if ($value > 255) {
                    // parse error
                    return false;
                }
            }

            if ($dotsSeen < 3 && $c != '.') {
                // parse error
                return false;
            }

            $piece = $piece * 0x100 + $value;

            if ($dotsSeen == 1 || $dotsSeen == 3) {
                $piecePointer++;
            }

            $pointer++;
            $c = substr($aInput, $pointer, 1);

            if ($dotsSeen == 3 && $c !== false) {
                // parse error
                return false;
            }

            $dotsSeen++;
        }

        Finale:
        if ($compressPointer !== null) {
            $swaps = $piecePointer - $compressPointer;
            $piecePointer = 7;

            while ($piecePointer !== 0 && $swaps > 0) {

            }
        } elseif ($compressPointer === null && $piecePointer != 8) {
            // parse error
            return false;
        }

        return $address;
    }

    // https://url.spec.whatwg.org/#concept-urlencoded-parser
    public static function urlencodedParser($aInput, $aEncoding = 'utf-8', $aUseCharset = null, $aIsIndex = null) {
        $input = $aInput;

        if ($aEncoding != 'utf-8') {
            for ($i = 0; $i < strlen($input); $i++) {
                if ($aInput[$i] > 0x7F) {
                    return false;
                }
            }
        }

        $sequences = explode('&', $input);

        if ($aIsIndex && !empty($squences) && strpos($squences[0], '=') === false) {
            $sequences[0] = '=' . $sequences[0];
        }

        $pairs = array();

        foreach ($sequences as $bytes) {
            if ($bytes === '') {
                continue;
            }

            $pos = strpos($bytes, '=');

            if ($pos !== false) {
                $name = substr($bytes, 0, $pos);
                $value = substr($bytes, $pos + 1) !== false ? substr($bytes, $pos + 1) : '';
            } else {
                $name = $bytes;
                $value = '';
            }

            $name = str_replace('+', chr(0x20), $name);
            $value = str_replace('+', chr(0x20), $value);

            $pairs[] = array('name' => $name, 'value' => $value);
        }

        return $pairs;
    }

    /**
     * Serializes a list of name-value pairs to be used in a URL.
     *
     * @link https://url.spec.whatwg.org/#concept-urlencoded-serializer
     *
     * @param  array  $aPairs    A list of name-value pairs to be serialized.
     *
     * @param  string $aEncoding Optionally allows you to set a different encoding to be used.
     *                           Default value is UTF-8.
     *
     * @return string
     */
    public static function urlencodedSerializer(array $aPairs, $aEncoding = 'UTF-8') {
        $output = '';

        foreach ($aPairs as $key => $pair) {
            if ($key > 0) {
                $output .= '&';
            }

            $output .= self::urlencodedByteSerializer(mb_convert_encoding($pair['name'], $aEncoding)) . '=';
            $output .= self::urlencodedByteSerializer(mb_convert_encoding($pair['value'], $aEncoding));
        }

        return $output;
    }

    /**
     * Serializes the individual bytes of the given byte sequence to be compatible with
     * application/x-www-form-encoded URLs.
     *
     * @link https://url.spec.whatwg.org/#concept-urlencoded-byte-serializer
     *
     * @param  string $aInput A byte sequence to be serialized.
     *
     * @return string
     */
    public static function urlencodedByteSerializer($aInput) {
        $output = '';

        for ($i = 0; $i < strlen($aInput); $i++) {
            $byte = ord($aInput[$i]);

            switch (true) {
                case ($byte == 0x20):
                    $output .= chr(0x2B);

                    break;

                case ($byte == 0x2A):
                case ($byte == 0x2D):
                case ($byte == 0x2E):
                case !($byte < 0x30 || $byte > 0x39):
                case !($byte < 0x41 || $byte > 0x5A):
                case ($byte == 0x5F):
                case !($byte < 0x61 || $byte > 0x7A):
                    $output .= $aInput[$i];

                    break;

                default:
                    $output .= self::percentEncode($aInput[$i]);
            }
        }

        return $output;
    }

    public static function urlencodedStringParser($aInput) {
        return self::urlencodedParser(self::encode($aInput));
    }

    /**
     * Encodes a code point stream if the code point is not part of the specified encode set.
     *
     * @link https://url.spec.whatwg.org/#utf-8-percent-encode
     *
     * @param  string   $aCodePoint A code point stream to be encoded.
     *
     * @param  int      $aEncodeSet The encode set used to decide whether or not the code point should
     *                              be encoded.
     * @return string
     */
    public static function utf8PercentEncode($aCodePoint, $aEncodeSet = self::ENCODE_SET_SIMPLE) {
        // The Simple Encode Set
        $inCodeSet = preg_match(self::REGEX_C0_CONTROLS, $aCodePoint) || ord($aCodePoint) > 0x7E;

        if (!$inCodeSet && $aEncodeSet <= self::ENCODE_SET_DEFAULT) {
            $inCodeSet = $inCodeSet || preg_match('/[\x{0020}"#<>?`,{}]/', $aCodePoint);
        }

        if (!$inCodeSet && $aEncodeSet <= self::ENCODE_SET_USERINFO) {
            $inCodeSet = $inCodeSet || preg_match('/[\/:;=@[\\\]^|]/', $aCodePoint);
        }

        if (!$inCodeSet) {
            return $aCodePoint;
        }

        $bytes = self::encode($aCodePoint);
        $result = '';

        for ($i = 0; $i < strlen($bytes); $i++) {
            $result .= self::percentEncode($bytes[$i]);
        }

        return $result;
    }

    public static function percentEncode($aByte) {
        return '%' . strtoupper(bin2hex($aByte));
    }

    public static function percentDecode($aByteSequence) {
        $output = '';

        for ($i = 0; $i < strlen($aByteSequence); $i++) {
            if ($aByteSequence[$i] != '%') {
                $output .= $aByteSequence[$i];
            } elseif ($aByteSequence[$i] == '%' && preg_match(self::REGEX_ASCII_HEX_DIGITS, substr($aByteSequence, $i + 1)) === false) {
                $output .= $aByteSequence[$i];
            } else {
                preg_match(self::REGEX_ASCII_HEX_DIGITS, substr($aByteSequence, $i + 1), $matches);

                $bytePoint = bin2hex(utf8_decode($matches[0][0]));
                $output .= $bytePoint;
                $i += 2;
            }
        }

        return $output;
    }

    public static function serializeHost($aHost = null) {
        if ($aHost === null) {
            return '';
        }

        if (self::IPv6Parser($aHost)) {
            return '[' . self::serializeIPv6($aHost) . ']';
        }

        return $aHost;
    }

    public static function serializeIPv6($aAddress) {
        $output = '';
        $compressPointer = null;

        return $output;
    }

    /**
     * Serializes a URL object.
     *
     * @link https://url.spec.whatwg.org/#concept-url-serializer
     *
     * @param  URL          $aUrl             The URL object to serialize.
     *
     * @param  bool|null    $aExcludeFragment Optional argument, that, when specified will exclude the URL's
     *                                        fragment from being serialized.
     * @return string
     */
    public static function serializeURL(URL $aUrl, $aExcludeFragment = null) {
        $output = $aUrl->mScheme . ':';

        if ($aUrl->mHost !== null) {
            $output .= '//';

            if ($aUrl->mUsername !== '' || $aUrl->mPassword !== null) {
                $output .= $aUrl->mUsername;

                if ($aUrl->mPassword !== null) {
                    $output .= ':' . $aUrl->mPassword;
                }

                $output .= '@';
            }

            $output .= self::serializeHost($aUrl->mHost);

            if ($aUrl->mPort !== null) {
                $output .= ':' . $aUrl->mPort;
            }
        } else if ($aUrl->mHost === null && $aUrl->mScheme == 'file') {
            $output .= '//';
        }

        if ($aUrl->mFlags & URL::FLAG_NON_RELATIVE) {
            $output .= $this->mPath[0];
        } else {
            $output .= '/';

            foreach ($aUrl->mPath as $key => $path) {
                if ($key > 0) {
                    $output .= '/';
                }

                $output .= $path;
            }
        }

        if ($aUrl->mQuery !== null) {
            $output .= '?' . $aUrl->mQuery;
        }

        if (!$aExcludeFragment && $aUrl->mFragment !== null) {
            $output .= '#' . $aUrl->mFragment;
        }

        return $output;
    }

    public static function utf8decode($aStream, $aEncoding = 'UTF-8') {
        return mb_convert_encoding($aStream, $aEncoding, 'UTF-8');
    }

    public static function encode($aStream, $aEncoding = 'UTF-8') {
        $inputEncoding = mb_detect_encoding($aStream);

        return mb_convert_encoding($aStream, $aEncoding, $inputEncoding);
    }
}
