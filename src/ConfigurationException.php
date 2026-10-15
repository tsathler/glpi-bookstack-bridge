<?php

namespace GlpiPlugin\Bookstackbridge;

use RuntimeException;

final class ConfigurationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The stored BookStack API secret could not be read. Enter the token secret again and save settings.');
    }
}
