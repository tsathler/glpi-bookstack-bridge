<?php

namespace GlpiPlugin\Bookstackbridge\Repository;

use GlpiPlugin\Bookstackbridge\EntityConfigurationException;
use Throwable;

/** Stores one protected BookStack connection per GLPI entity. */
final class EntityConnectionRepository
{
    public const TABLE = 'glpi_plugin_bookstackbridge_connections';

    /** @return array<string, mixed> */
    public static function getForEntity(int $entityId): array
    {
        self::assertEntityId($entityId);
        $row = self::findRow($entityId);
        if ($row === null) {
            return self::withState([
                'entities_id' => $entityId,
                'base_url' => '',
                'allowed_private_ips' => '',
                'api_token_id' => '',
                'api_token' => '',
            ]);
        }
        return self::withState(self::normalizeRow($row));
    }

    /**
     * Save submitted fields, preserving omitted or blank credentials. The
     * caller must validate the URL and allowlist before calling this method.
     *
     * @param array<string, mixed> $submitted
     * @return array<string, mixed> Decrypted backend values; never render directly.
     */
    public static function saveForEntity(int $entityId, array $submitted): array
    {
        self::assertEntityId($entityId);
        $existingRow = self::findRow($entityId);
        $current = $existingRow === null
            ? ['base_url' => '', 'allowed_private_ips' => '', 'api_token_id' => '', 'api_token' => '']
            : self::normalizeForSubmission($existingRow, $submitted);

        $values = [
            'entities_id' => $entityId,
            'base_url' => self::submittedString($submitted, 'base_url', $current['base_url']),
            'allowed_private_ips' => self::submittedString($submitted, 'allowed_private_ips', $current['allowed_private_ips']),
            'api_token_id' => self::replacementOrCurrent($submitted, 'api_token_id', $current['api_token_id']),
            'api_token' => self::replacementOrCurrent($submitted, 'api_token', $current['api_token']),
        ];

        $stored = $values;
        $stored['api_token_id'] = $values['api_token_id'] === ''
            ? null
            : self::encryptCredential($values['api_token_id']);
        $stored['api_token'] = $values['api_token'] === ''
            ? null
            : self::encryptCredential($values['api_token']);

        global $DB;
        if ($existingRow === null) {
            $success = $DB->insert(self::TABLE, $stored);
        } else {
            $success = $DB->update(self::TABLE, $stored, ['id' => $existingRow['id']]);
        }
        if ($success === false) {
            throw new EntityConfigurationException();
        }
        return self::withState($values);
    }

    /**
     * Copy the legacy global configuration to exactly one selected entity.
     * It requires an entity-bound confirmation phrase and backup confirmation.
     * The global source is retained as a recovery copy until a later cutover.
     *
     * @return array<string, mixed>
     */
    public static function copyLegacyConfigurationToEntity(
        int $entityId,
        string $confirmation,
        bool $backupConfirmed
    ): array {
        self::assertEntityId($entityId);
        if ($confirmation !== 'MIGRATE TO ENTITY ' . $entityId || !$backupConfirmed) {
            throw new EntityConfigurationException('Confirm the selected entity and a current backup before copying the legacy connection.');
        }
        if (self::findRow($entityId) !== null) {
            throw new EntityConfigurationException('This entity already has a connection. No configuration was changed.');
        }

        $legacy = \GlpiPlugin\Bookstackbridge\ConfigRepository::getConfigurationValues();
        $hasLegacySettings = trim((string) ($legacy['base_url'] ?? '')) !== ''
            || trim((string) ($legacy['allowed_private_ips'] ?? '')) !== ''
            || trim((string) ($legacy['api_token_id'] ?? '')) !== ''
            || trim((string) ($legacy['api_token'] ?? '')) !== '';
        if (!$hasLegacySettings) {
            throw new EntityConfigurationException('There is no legacy connection to copy.');
        }

        return self::saveForEntity($entityId, [
            'base_url' => (string) ($legacy['base_url'] ?? ''),
            'allowed_private_ips' => (string) ($legacy['allowed_private_ips'] ?? ''),
            'api_token_id' => (string) ($legacy['api_token_id'] ?? ''),
            'api_token' => (string) ($legacy['api_token'] ?? ''),
        ]);
    }

    /** @return array<string, mixed>|null */
    private static function findRow(int $entityId): ?array
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            throw new EntityConfigurationException('Entity connection storage is not installed.');
        }
        $rows = [];
        foreach ($DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['entities_id' => $entityId],
        ]) as $row) {
            $rows[] = $row;
        }
        if (count($rows) > 1) {
            throw new EntityConfigurationException('Entity connection storage contains duplicate records.');
        }
        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function normalizeRow(array $row): array
    {
        return [
            'entities_id' => (int) $row['entities_id'],
            'base_url' => (string) ($row['base_url'] ?? ''),
            'allowed_private_ips' => (string) ($row['allowed_private_ips'] ?? ''),
            'api_token_id' => self::decryptCredential((string) ($row['api_token_id'] ?? '')),
            'api_token' => self::decryptCredential((string) ($row['api_token'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $submitted @return array<string, string> */
    private static function normalizeForSubmission(array $row, array $submitted): array
    {
        $values = [
            'base_url' => (string) ($row['base_url'] ?? ''),
            'allowed_private_ips' => (string) ($row['allowed_private_ips'] ?? ''),
        ];
        foreach (['api_token_id', 'api_token'] as $field) {
            try {
                $values[$field] = self::decryptCredential((string) ($row[$field] ?? ''));
            } catch (EntityConfigurationException $e) {
                if (trim((string) ($submitted[$field] ?? '')) === '') {
                    throw $e;
                }
                // A replacement for this specific credential permits recovery
                // without carrying unreadable ciphertext into the next write.
                $values[$field] = '';
            }
        }
        return $values;
    }

    /** @param array<string, mixed> $submitted */
    private static function submittedString(array $submitted, string $field, string $fallback): string
    {
        if (!array_key_exists($field, $submitted)) {
            return $fallback;
        }
        if (!is_string($submitted[$field]) || strlen($submitted[$field]) > ($field === 'base_url' ? 2048 : 4096)) {
            throw new EntityConfigurationException('Entity connection input is invalid.');
        }
        return trim($submitted[$field]);
    }

    /** @param array<string, mixed> $submitted */
    private static function replacementOrCurrent(array $submitted, string $field, string $current): string
    {
        if (!array_key_exists($field, $submitted)) {
            return $current;
        }
        if (!is_string($submitted[$field]) || strlen($submitted[$field]) > 2048) {
            throw new EntityConfigurationException('Entity credential input is invalid.');
        }
        $replacement = trim($submitted[$field]);
        return $replacement !== '' ? $replacement : $current;
    }

    private static function encryptCredential(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            set_error_handler(static fn (int $severity): bool => $severity === E_USER_WARNING);
            try {
                $encrypted = (new \GLPIKey())->encrypt($value);
            } finally {
                restore_error_handler();
            }
        } catch (Throwable) {
            throw new EntityConfigurationException('GLPI could not protect the entity credentials.');
        }
        if (!is_string($encrypted) || $encrypted === '' || $encrypted === $value) {
            throw new EntityConfigurationException('GLPI could not protect the entity credentials.');
        }
        return $encrypted;
    }

    private static function decryptCredential(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            set_error_handler(static fn (int $severity): bool => $severity === E_USER_WARNING);
            try {
                $plain = (new \GLPIKey())->decrypt($value);
            } finally {
                restore_error_handler();
            }
        } catch (Throwable) {
            throw new EntityConfigurationException('A stored entity credential cannot be read. Replace that credential to continue.');
        }
        if (!is_string($plain) || $plain === '' || $plain === $value) {
            throw new EntityConfigurationException('A stored entity credential cannot be read. Replace that credential to continue.');
        }
        return $plain;
    }

    /** @param int $entityId Root entity 0 is valid in GLPI. */
    private static function assertEntityId(int $entityId): void
    {
        if ($entityId < 0) {
            throw new EntityConfigurationException('The selected GLPI entity is invalid.');
        }
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function withState(array $values): array
    {
        $networkConfigured = trim((string) ($values['base_url'] ?? '')) !== '';
        $credentialsComplete = trim((string) ($values['api_token_id'] ?? '')) !== ''
            && trim((string) ($values['api_token'] ?? '')) !== '';
        $values['network_configured'] = $networkConfigured;
        $values['credentials_complete'] = $credentialsComplete;
        $values['state'] = !$networkConfigured ? 'network_pending' : (!$credentialsComplete ? 'credentials_pending' : 'ready');
        return $values;
    }
}
