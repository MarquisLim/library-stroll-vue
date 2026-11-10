<?php

namespace App\Services\DemoContent;

use RuntimeException;

class MediaUrlPolicy
{
    public function options(string $url, array $hosts): array
    {
        $p = parse_url($url);
        $host = strtolower($p['host'] ?? '');
        if (($p['scheme'] ?? '') !== 'https' || ! in_array($host, $hosts, true) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port'] !== 443)) {
            throw new RuntimeException('Unsafe media URL');
        }
        $addresses = $this->addresses($host);
        if (! $addresses) {
            throw new RuntimeException('Media DNS resolution failed');
        }
        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE)) {
                throw new RuntimeException('Private media address rejected');
            }
        }

        return ['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["$host:443:".$addresses[0]]]];
    }

    public function addresses(string $host): array
    {
        return gethostbynamel($host) ?: [];
    }
}
