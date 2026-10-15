<?php

namespace GlpiPlugin\Bookstackbridge\Security;

use GlpiPlugin\Bookstackbridge\Api\BookStackException;

final class BaseUrlPolicy
{
    /**
     * Validate a BookStack URL and resolve it to an address that the HTTP
     * transport can pin for the lifetime of the client.
     *
     * @param string|list<string> $privateIpAllowlist Exact private IPs; CIDRs are not accepted.
     * @param null|callable(string): array $resolver Test seam; returns every A/AAAA address.
     * @return array{url: string, host: string, port: int, ip: string, is_literal: bool}
     */
    public static function resolve(string $url, string|array $privateIpAllowlist = '', ?callable $resolver = null): array
    {
        $allowlist = self::parsePrivateIpAllowlist($privateIpAllowlist);
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new BookStackException('BookStack URL contains invalid characters.');
        }

        try {
            $parts = parse_url($url);
        } catch (\Throwable) {
            $parts = false;
        }
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
            throw new BookStackException('BookStack URL must be an HTTPS origin, with an optional path prefix.');
        }

        $port = $parts['port'] ?? 443;
        if (!is_int($port) || $port !== 443) {
            throw new BookStackException('Only the standard HTTPS port is allowed for BookStack.');
        }

        $rawHost = (string) $parts['host'];
        if (str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']')) {
            $rawHost = substr($rawHost, 1, -1);
        }
        $host = strtolower($rawHost);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $host = self::canonicalIp($host);
            $addresses = [$host];
            $isLiteral = true;
        } else {
            if (str_ends_with($host, '.')) {
                $host = substr($host, 0, -1);
            }
            self::validateHostname($host);
            try {
                $addresses = $resolver !== null
                    ? $resolver($host)
                    : self::lookupAddresses($host);
            } catch (\Throwable) {
                throw new BookStackException('BookStack hostname could not be resolved. Check DNS and network configuration.');
            }
            if (!is_array($addresses) || $addresses === [] || count($addresses) > 64) {
                throw new BookStackException('BookStack hostname could not be resolved. Check DNS and network configuration.');
            }
            $isLiteral = false;
        }

        $normalizedAddresses = [];
        foreach ($addresses as $address) {
            if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new BookStackException('BookStack DNS returned an invalid address.');
            }
            $address = self::canonicalIp($address);
            self::assertAddressAllowed($address, $allowlist);
            $normalizedAddresses[] = $address;
        }
        $normalizedAddresses = array_values(array_unique($normalizedAddresses));
        if ($normalizedAddresses === []) {
            throw new BookStackException('BookStack hostname has no usable network address.');
        }

        $path = (string) ($parts['path'] ?? '');
        $decodedPath = rawurldecode($path);
        if (str_contains($decodedPath, "\\") || str_contains($decodedPath, "\0")) {
            throw new BookStackException('BookStack URL path is invalid.');
        }
        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '..') {
                throw new BookStackException('BookStack URL path is invalid.');
            }
        }

        $urlHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        return [
            'url' => 'https://' . $urlHost . rtrim($path, '/'),
            'host' => $host,
            'port' => 443,
            'ip' => $normalizedAddresses[0],
            'is_literal' => $isLiteral,
        ];
    }

    /** @param string|list<string> $input @return list<string> */
    public static function parsePrivateIpAllowlist(string|array $input): array
    {
        if (is_string($input) && strlen($input) > 4096) {
            throw new BookStackException('Private destination allowlist is too long.');
        }
        $entries = is_array($input) ? $input : preg_split('/\r\n|\r|\n/', $input);
        if (!is_array($entries) || count($entries) > 64) {
            throw new BookStackException('Private destination allowlist is invalid.');
        }
        $allowlist = [];
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                throw new BookStackException('Private destination allowlist must contain one IP address per line.');
            }
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (filter_var($entry, FILTER_VALIDATE_IP) === false) {
                throw new BookStackException('Allowlist entries must be exact IP addresses; hostnames and CIDR ranges are not accepted.');
            }
            $entry = self::canonicalIp($entry);
            if (self::isExplicitlyForbidden($entry) || !self::isPrivateAddress($entry)) {
                throw new BookStackException('Allowlist entries must be safe private IP addresses.');
            }
            $allowlist[] = $entry;
        }
        return array_values(array_unique($allowlist));
    }

    public static function validate(string $url, string|array $privateIpAllowlist = '', ?callable $resolver = null): string
    {
        return self::resolve($url, $privateIpAllowlist, $resolver)['url'];
    }

    private static function validateHostname(string $host): void
    {
        if (strlen($host) > 253 || str_contains($host, '..')
            || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new BookStackException('BookStack hostname is invalid or local.');
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            throw new BookStackException('BookStack hostname must be a fully qualified DNS name.');
        }
        foreach ($labels as $label) {
            if (strlen($label) > 63 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/i', $label) !== 1) {
                throw new BookStackException('BookStack hostname is invalid.');
            }
        }
        if (preg_match('/\A(?:[0-9]+|0x[0-9a-f]+)(?:\.(?:[0-9]+|0x[0-9a-f]+))*\z/i', $host) === 1) {
            throw new BookStackException('BookStack IP address is invalid.');
        }
    }

    /** @return list<string> */
    private static function lookupAddresses(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (($record['type'] ?? '') === 'A' && isset($record['ip'])) {
                    $addresses[] = $record['ip'];
                } elseif (($record['type'] ?? '') === 'AAAA' && isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
        return array_values(array_unique($addresses));
    }

    /** @param list<string> $allowlist */
    private static function assertAddressAllowed(string $ip, array $allowlist): void
    {
        if (self::isExplicitlyForbidden($ip)) {
            throw new BookStackException('BookStack destination is a loopback, link-local, metadata, multicast, or reserved address.');
        }
        if (self::isPrivateAddress($ip)) {
            if (!in_array($ip, $allowlist, true)) {
                throw new BookStackException('BookStack resolves to a private IP address that is not explicitly allowlisted.');
            }
            return;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new BookStackException('BookStack destination is a reserved or unsupported address.');
        }
    }

    private static function isPrivateAddress(string $ip): bool
    {
        return self::inAnyCidr($ip, [
            '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
            'fc00::/7',
        ]);
    }

    private static function isExplicitlyForbidden(string $ip): bool
    {
        return self::inAnyCidr($ip, [
            '0.0.0.0/8', '127.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16',
            '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24',
            '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
            '168.63.129.16/32',
            '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48',
            '100::/64', '100:0:0:1::/64', '2001::/23', '2001:2::/48', '2001:db8::/32',
            '2002::/16', '3fff::/20', '5f00::/16', 'fe80::/10', 'ff00::/8',
            'fd00:ec2::254/128',
        ]);
    }

    /** @param list<string> $cidrs */
    private static function inAnyCidr(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);
        $ipBytes = inet_pton($ip);
        $networkBytes = inet_pton($network);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }
        $prefix = (int) $prefix;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if (substr($ipBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($ipBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }

    private static function canonicalIp(string $ip): string
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            throw new BookStackException('BookStack IP address is invalid.');
        }
        $canonical = inet_ntop($packed);
        if (!is_string($canonical)) {
            throw new BookStackException('BookStack IP address is invalid.');
        }
        return strtolower($canonical);
    }
}
