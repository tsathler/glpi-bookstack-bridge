<?php

namespace GlpiPlugin\Bookstackbridge;

use Throwable;

/**
 * Reads and writes this plugin's GLPI configuration, normalizing the protected
 * BookStack API secret to plaintext only for backend use.
 */
final class ConfigRepository
{
    private const CONTEXT = 'plugin:bookstackbridge';
    private const SECRET_NAME = 'api_token';

    /** @return array<string, mixed> */
    public static function getConfigurationValues(): array
    {
        return self::decryptSecret(\Config::getConfigurationValues(self::CONTEXT));
    }

    /**
     * Read values for a settings update. A replacement secret allows recovery
     * when the previously stored secret cannot be decrypted.
     *
     * @return array<string, mixed>
     */
    public static function getConfigurationValuesForSubmission(string $replacementSecret): array
    {
        $values = \Config::getConfigurationValues(self::CONTEXT);
        try {
            return self::decryptSecret($values);
        } catch (ConfigurationException $e) {
            if (trim($replacementSecret) === '') {
                throw $e;
            }

            // The caller supplied a replacement. Never pass unreadable stored
            // ciphertext into Config::setConfigurationValues(), which would
            // encrypt it again.
            $values[self::SECRET_NAME] = '';
            return $values;
        }
    }

    /**
     * Return only non-secret configuration for an error page.
     *
     * @return array<string, mixed>
     */
    public static function getNonSecretConfigurationValues(): array
    {
        $values = \Config::getConfigurationValues(self::CONTEXT);
        $values[self::SECRET_NAME] = '';
        $values['api_token_id'] = '';
        return $values;
    }

    /** @param array<string, mixed> $values */
    public static function setConfigurationValues(array $values): void
    {
        \Config::setConfigurationValues(self::CONTEXT, $values);
    }

    /** @param array<string, mixed> $values
     *  @return array<string, mixed>
     */
    private static function decryptSecret(array $values): array
    {
        $storedSecret = (string) ($values[self::SECRET_NAME] ?? '');
        if ($storedSecret === '') {
            $values[self::SECRET_NAME] = '';
            return $values;
        }

        try {
            // GLPIKey reports invalid ciphertext via a warning and an empty
            // return value. Suppress its implementation detail and convert
            // every failure to the plugin's safe, actionable message.
            set_error_handler(static function (int $severity): bool {
                return $severity === E_USER_WARNING;
            });
            try {
                $secret = (new \GLPIKey())->decrypt($storedSecret);
            } finally {
                restore_error_handler();
            }
        } catch (Throwable) {
            throw new ConfigurationException();
        }

        if (!is_string($secret) || $secret === '' || $secret === $storedSecret) {
            throw new ConfigurationException();
        }

        $values[self::SECRET_NAME] = $secret;
        return $values;
    }
}
