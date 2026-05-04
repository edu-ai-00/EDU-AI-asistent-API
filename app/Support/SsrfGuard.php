<?php

namespace App\Support;

class SsrfGuard
{
    /**
     * Validate URL is safe to fetch server-side.
     * Blocks: non-http(s) schemes, private/loopback/link-local IPs, cloud metadata endpoints.
     *
     * @return array{ok: bool, reason?: string, host?: string, scheme?: string}
     */
    public static function validate(string $url, array $allowedSchemes = ['http', 'https']): array
    {
        $parts = parse_url($url);

        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return ['ok' => false, 'reason' => 'invalid_url'];
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, $allowedSchemes, true)) {
            return ['ok' => false, 'reason' => 'scheme_not_allowed'];
        }

        $host = strtolower($parts['host']);

        // Block cloud metadata endpoints by literal host name.
        $blockedHosts = ['metadata.google.internal', 'metadata', 'metadata.goog'];
        if (in_array($host, $blockedHosts, true)) {
            return ['ok' => false, 'reason' => 'blocked_host'];
        }

        // Resolve all A / AAAA records and reject if any is private.
        $ips = self::resolveHost($host);
        if (empty($ips)) {
            return ['ok' => false, 'reason' => 'dns_failed'];
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return ['ok' => false, 'reason' => 'private_ip'];
            }
        }

        return ['ok' => true, 'host' => $host, 'scheme' => $scheme];
    }

    /**
     * Resolve hostname to all IPv4 + IPv6 addresses.
     */
    private static function resolveHost(string $host): array
    {
        // If host is already a literal IP, return as-is.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                }
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        // Fallback to gethostbynamel for IPv4.
        if (empty($ips)) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
        }

        return array_unique($ips);
    }

    /**
     * True if IP is public (not private, reserved, loopback, link-local).
     */
    private static function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
