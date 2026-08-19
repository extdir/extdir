<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The real resolver, asking the system for A and AAAA records.
 *
 * Both families are looked up together on purpose. Resolving only A records and
 * connecting anyway would let a name hide a private IPv6 address behind a public
 * IPv4 one, and on a dual-stack host the client may well prefer the address that
 * was never inspected.
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, \DNS_A + \DNS_AAAA);

        if (false === $records) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (\is_string($address) && '' !== $address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
