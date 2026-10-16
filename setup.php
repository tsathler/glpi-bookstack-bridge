<?php

use Glpi\Plugin\Hooks;

define('PLUGIN_BOOKSTACKBRIDGE_VERSION', '0.2.0');
define('PLUGIN_BOOKSTACKBRIDGE_MIN_GLPI', '11.0.0');
define('PLUGIN_BOOKSTACKBRIDGE_MAX_GLPI', '11.1.0');

function plugin_init_bookstackbridge(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['bookstackbridge'] = true;
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['bookstackbridge'] = ['api_token'];
    $PLUGIN_HOOKS[Hooks::SECURED_FIELDS]['bookstackbridge'] = [
        'glpi_plugin_bookstackbridge_connections.api_token_id',
        'glpi_plugin_bookstackbridge_connections.api_token',
    ];
    $PLUGIN_HOOKS['menu_toadd']['bookstackbridge']['tools'] = [
        GlpiPlugin\Bookstackbridge\Menu::class,
    ];
}

function plugin_version_bookstackbridge(): array
{
    return [
        'name' => 'GLPI BookStack Bridge',
        'version' => PLUGIN_BOOKSTACKBRIDGE_VERSION,
        'author' => 'GLPI BookStack Bridge contributors',
        'license' => 'GPL-3.0-or-later',
        'homepage' => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_BOOKSTACKBRIDGE_MIN_GLPI,
                'max' => PLUGIN_BOOKSTACKBRIDGE_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_bookstackbridge_check_prerequisites(): bool
{
    $missing = [];
    foreach (['curl', 'sodium'] as $extension) {
        if (!extension_loaded($extension)) {
            $missing[] = $extension;
        }
    }
    if ($missing !== []) {
        echo 'GLPI BookStack Bridge requires PHP extensions: ' . htmlspecialchars(implode(', ', $missing), ENT_QUOTES, 'UTF-8');
        return false;
    }
    return true;
}

function plugin_bookstackbridge_check_config(bool $verbose = false): bool
{
    return true;
}
