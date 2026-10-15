<?php

namespace GlpiPlugin\Bookstackbridge;

final class ConfigSubmission
{
    /**
     * @param array<string, mixed> $current
     * @param list<string> $privateIpAllowlist
     * @return array{base_url: string, allowed_private_ips: string, api_token_id: string, api_token: string}
     */
    public static function merge(
        array $current,
        string $url,
        array $privateIpAllowlist,
        string $submittedTokenId,
        string $submittedTokenSecret
    ): array {
        $tokenId = trim($submittedTokenId);
        $tokenSecret = trim($submittedTokenSecret);

        return [
            'base_url' => $url,
            'allowed_private_ips' => implode("\n", $privateIpAllowlist),
            'api_token_id' => $tokenId !== '' ? $tokenId : (string) ($current['api_token_id'] ?? ''),
            'api_token' => $tokenSecret !== '' ? $tokenSecret : (string) ($current['api_token'] ?? ''),
        ];
    }
}
