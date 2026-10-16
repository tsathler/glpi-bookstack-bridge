<?php

function plugin_bookstackbridge_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_BOOKSTACKBRIDGE_VERSION);
    $table = GlpiPlugin\Bookstackbridge\Repository\EntityConnectionRepository::TABLE;
    if (!$DB->tableExists($table)) {
        $charset = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $query = "CREATE TABLE `$table` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `base_url` text NOT NULL,
            `allowed_private_ips` text NOT NULL,
            `api_token_id` text DEFAULT NULL,
            `api_token` text DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity_entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";
        if (!$DB->doQuery($query, $DB->error())) {
            return false;
        }
    }
    $migration->executeMigration();

    // Preserve existing M0 settings on updates. The legacy global settings are
    // intentionally not copied to any entity during installation.
    $context = 'plugin:bookstackbridge';
    $existing = Config::getConfigurationValues($context);
    $defaults = [
        'base_url' => '',
        'allowed_private_ips' => '',
        'api_token_id' => '',
        'api_token' => '',
    ];
    $missing = array_diff_key($defaults, $existing);
    if ($missing !== []) {
        Config::setConfigurationValues($context, $missing);
    }
    return true;
}

function plugin_bookstackbridge_uninstall(): bool
{
    global $DB;

    $table = GlpiPlugin\Bookstackbridge\Repository\EntityConnectionRepository::TABLE;
    if ($DB->tableExists($table) && !$DB->doQuery("DROP TABLE `$table`", $DB->error())) {
        return false;
    }
    $config = new Config();
    $config->deleteByCriteria(['context' => 'plugin:bookstackbridge']);
    return true;
}
