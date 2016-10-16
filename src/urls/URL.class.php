<?php
namespace phpjs\urls;

use phpjs\exceptions\TypeError;
use phpjs\Utils;

/**
 * Represents a URL that can be manipulated.
 *
 * @see https://url.spec.whatwg.org/#api
 * @see https://developer.mozilla.org/en-US/docs/Web/API/URL
 */
class URL
{
    private $mSearchParams;
    private $mUrl;

	public function __construct($aUrl, $aBase = null)
    {
        $this->mSearchParams = null;
        $this->mUrl = null;
        $parsedBase = null;

        if ($aBase) {
            $parsedBase = URLInternal::basicURLParser($aBase);

            if ($parsedBase === false) {
                throw new TypeError($aBase . ' is not a valid URL.');
            }
        }

        $parsedURL = URLInternal::basicURLParser($aUrl, $parsedBase);

        if ($parsedURL === false) {
            throw new TypeError($aUrl . ' is not a valid URL.');
        }

        $this->mUrl = $parsedURL;
        $query = $this->mUrl->getQuery();
        $query = $query === null ? '' : '?' . $query;
        $this->mSearchParams = new URLSearchParams($query);
        $this->mSearchParams->_setUrl($parsedURL);
	}

    public function __destruct()
    {
        $this->mSearchParams = null;
        $this->mUrl = null;
    }

    public function __get($aName)
    {
        switch ($aName) {
            case 'hash':
                $fragment = $this->mUrl->getFragment();

                return !$fragment ? '' : '#' . $fragment;

            case 'host':
                $host = $this->mUrl->getHost();
                $port = $this->mUrl->getPort();

                if ($host === null) {
                    return '';
                }

                if ($port === null) {
                    return HostFactory::serialize($host);
                }

                return HostFactory::serialize($host) . ':' . $port;

            case 'hostname':
                $host = $this->mUrl->getHost();

                return $host === null ? '' : HostFactory::serialize($host);

            case 'href':
                return $this->mUrl->serializeURL();

            case 'origin':
                return $this->mUrl->getOrigin()->serializeAsUnicode();

            case 'password':
                $password = $this->mUrl->getPassword();

                return $password === null ? '' : $password;

            case 'pathname':
                if ($this->mUrl->isFlagSet(
                        URLInternal::FLAG_CANNOT_BE_A_BASE_URL
                )) {
                    return $this->mUrl->getPath()[0];
                }

                $output = '/';

                foreach ($this->mUrl->getPath() as $key => $path) {
                    if ($key > 0) {
                        $output .= '/';
                    }

                    $output .= $path;
                }

                return $output;

            case 'port':
                $port = $this->mUrl->getPort();

                return $port === null ? '' : $port;

            case 'protocol':
                return $this->mUrl->getScheme() . ':';

            case 'search':
                $query = $this->mUrl->getQuery();

                return !$query ? '' : '?' . $query;

            case 'searchParams':
                return $this->mSearchParams;

            case 'username':
                return $this->mUrl->getUsername();

        }
    }

    public function __set($aName, $aValue)
    {
        $value = Utils::DOMString($aValue);

        // Treat all non-string values as an empty string.
        if (!is_string($aValue)) {
            $value = '';
        }

        switch ($aName) {
            case 'hash':
                if ($this->mUrl->getScheme() == 'javascript') {
                    // Terminate these steps
                    return;
                }

                if ($value === '') {
                    $this->mUrl->setFragment(null);

                    // Terminate these steps
                    return;
                }

                $input = $value[0] == '#' ? substr($value, 1) : $value;
                $this->mUrl->setFragment('');
                URLInternal::basicURLParser(
                    $input,
                    null,
                    null,
                    $this->mUrl,
                    URLInternal::FRAGMENT_STATE
                );

                break;

            case 'host':
                if ($this->mUrl->isFlagSet(
                        URLInternal::FLAG_CANNOT_BE_A_BASE_URL
                )) {
                    // Terminate these steps
                    return;
                }

                URLInternal::basicURLParser(
                    $value,
                    null,
                    null,
                    $this->mUrl,
                    URLInternal::HOST_STATE
                );

                break;

            case 'hostname':
                if ($this->mUrl->isFlagSet(
                        URLInternal::FLAG_CANNOT_BE_A_BASE_URL
                )) {
                    // Terminate these steps
                    return;
                }

                URLInternal::basicURLParser(
                    $value,
                    null,
                    null,
                    $this->mUrl,
                    URLInternal::HOSTNAME_STATE
                );

                break;

            case 'href':
                $parsedURL = URLInternal::basicURLParser($value);

                if ($parsedURL === false) {
                    throw new TypeError($value . ' is not a valid URL.');
                }

                $this->mUrl = $parsedURL;
                $query = $this->mUrl->getQuery();
                $query = $query === null ? '' : '?' . $query;
                $this->mSearchParams = new URLSearchParams($query);
                $this->mSearchParams->_setUrl($parsedURL);

                break;

            case 'password':
                if ($this->mUrl->getHost() === null ||
                    $this->mUrl->isFlagSet(
                        URLInternal::FLAG_CANNOT_BE_A_BASE_URL
                    )
                ) {
                    // Terminate these steps
                    return;
                }

                $this->mUrl->setPasswordSteps($value);

                break;

            case 'pathname':
                if ($this->mUrl->isFlagSet(
                        URLInternal::FLAG_CANNOT_BE_A_BASE_URL
                )) {
                    // Terminate these steps
                    return;
                }

                while (!$this->mUrl->getPath()->isEmpty()) {
                    $this->mUrl->getPath()->pop();
                }

                URLInternal::basicURLParser(
                    $value,
                    null,
                    null,
                    $this->mUrl,
                    URLInternal::PATH_START_STATE
                );

                break;

            case 'port':
                if ($this->mUrl->getHost() === null ||
                    $this->mUrl->isFlagSet(
                        URLInternal::FLAG_CANNOT_BE_A_BASE_URL
                    ) ||
                    $this->mUrl->getScheme() == 'file'
                ) {
                    // Terminate these steps
                    return;
                }

                URLInternal::basicURLParser(
                    $value,
                    null,
                    null,
                    $this->mUrl,
                    URLInternal::PORT_STATE
                );

                break;

            case 'protocol':
                URLInternal::basicURLParser(
                    $value . ':',
                    null,
                    null,
                    $this->mUrl,
                    URLInternal::SCHEME_START_STATE
                );

                break;

            case 'search':
                $query = $this->mUrl->getQuery();

                if ($value === '') {
                    $this->mUrl->setQuery(null);
                    $this->mSearchParams->_mutateList(null);

                    return;
                }

                $input = $value[0] == '?' ? substr($value, 1) : $value;
                $this->mUrl->setQuery('');
                URLInternal::basicURLParser(
                    $input,
                    null,
                    null,
                    $this->mUrl,
                    URLInternal::QUERY_STATE
                );
                $this->mSearchParams->_mutateList(
                    URLUtils::urlencodedStringParser($input)
                );

                break;

            case 'username':
                if ($this->mUrl->getHost() === null ||
                    $this->mUrl->isFlagSet(
                        URLInternal::FLAG_CANNOT_BE_A_BASE_URL
                )) {
                    // Terminate these steps
                    return;
                }

                $this->mUrl->setUsernameSteps($value);

                break;
        }
    }
}