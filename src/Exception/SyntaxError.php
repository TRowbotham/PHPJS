<?php

declare(strict_types=1);

namespace Rowbot\DOM\Exception;

use Throwable;

/**
 * @see https://heycam.github.io/webidl/#syntaxerror
 */
class SyntaxError extends DOMException
{
    public function __construct(string $message = '', Throwable $previous = null)
    {
        if ($message === '') {
            $message = 'The string did not match the expected pattern.';
        }

        parent::__construct($message, 12, $previous);
    }
}
