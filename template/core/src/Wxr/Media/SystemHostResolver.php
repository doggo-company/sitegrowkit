<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Media;

/**
 * 実際の名前解決（Phase 9.14 / STAGE 27E-3）。
 *
 * IPv4 と IPv6 の両方を引く。**片方だけ引くと、もう片方で抜けられる** —
 * A が公開 IP で AAAA が `::1` のホストは、A だけ見れば通ってしまう。
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        // 既に IP ならそのまま（解決しない — 解決すると意味が変わる）
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        foreach (['A', 'AAAA'] as $type) {
            $records = @dns_get_record($host, $type === 'A' ? DNS_A : DNS_AAAA);

            foreach ($records === false ? [] : $records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
