<?php

namespace GlpiPlugin\Bookstackbridge\Api;

use RuntimeException;

final class BookStackException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null)
    {
        parent::__construct($message);
    }
}
