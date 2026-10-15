<?php

function plugin_bookstackbridge_install(): bool
{
    Config::setConfigurationValues('plugin:bookstackbridge', [
        'base_url' => '',
        'api_token_id' => '',
        'api_token' => '',
    ]);
    return true;
}

function plugin_bookstackbridge_uninstall(): bool
{
    $config = new Config();
    $config->deleteByCriteria(['context' => 'plugin:bookstackbridge']);
    return true;
}
