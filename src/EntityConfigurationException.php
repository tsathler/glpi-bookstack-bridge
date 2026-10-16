<?php

namespace GlpiPlugin\Bookstackbridge;

use RuntimeException;

final class EntityConfigurationException extends RuntimeException
{
    public function __construct(string $message = 'The entity BookStack configuration could not be safely read or saved.')
    {
        parent::__construct($message);
    }
}
