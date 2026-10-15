<?php

namespace GlpiPlugin\Bookstackbridge\Security;

use GlpiPlugin\Bookstackbridge\Api\BookStackException;

final class BaseUrlPolicy
{
    public static function validate(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new BookStackException('BookStack URL must be an HTTPS origin, with an optional path prefix.');
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new BookStackException('Local or private BookStack hosts are not allowed.');
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw new BookStackException('Only the standard HTTPS port is allowed for BookStack.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
            if ($ips === []) {
                throw new BookStackException('BookStack host could not be resolved for SSRF validation.');
            }
        }
        foreach (array_unique($ips) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new BookStackException('BookStack host resolves to a private or reserved address.');
            }
        }
        $path = rtrim($parts['path'] ?? '', '/');
        $decodedPath = rawurldecode($path);
        if (str_contains($decodedPath, '..') || str_contains($decodedPath, "\\")) {
            throw new BookStackException('BookStack URL path is invalid.');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return 'https://' . $host . $port . $path;
    }
}
