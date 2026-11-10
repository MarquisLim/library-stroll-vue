<?php

namespace App\Services\DemoContent;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SourceHttp
{
    private array $last = [];

    private ?string $openverseToken = null;

    private float $openverseTokenExpiresAt = 0;

    private array $cache = [];

    public array $known = [];

    public array $metrics = [];

    public function reset(): void
    {
        $this->cache = [];
        $this->known = [];
        $this->metrics = [];
        $this->openverseToken = null;
    }

    public function record(string $source, string $metric, int $amount = 1): void
    {
        $this->metrics[$source][$metric] = ($this->metrics[$source][$metric] ?? 0) + $amount;
    }

    public function throttle(string $source): void
    {
        $delay = max(0, 1 / max(.01, config("demo_content.sources.$source.rate_limit")) - (microtime(true) - ($this->last[$source] ?? 0)));
        if ($delay > 0) {
            usleep((int) ($delay * 1000000));
        }
        $this->last[$source] = microtime(true);
    }

    public function get(string $source, string $endpoint, array $params = []): array
    {
        $c = config("demo_content.sources.$source");
        $cacheKey = hash('sha256', serialize([$source, $endpoint, $params]));
        if (isset($this->cache[$cacheKey])) {
            $this->record($source, 'cache_hits');

            return $this->cache[$cacheKey];
        }
        $url = rtrim($c['base_url'], '/').'/'.ltrim($endpoint, '/');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('API must use HTTPS');
        }
        if ($source === 'openverse' && $c['client_id'] && $c['client_secret'] && (! $this->openverseToken || microtime(true) >= $this->openverseTokenExpiresAt)) {
            $token = Http::asForm()->timeout($c['request_timeout'])->connectTimeout($c['connect_timeout'])->withOptions(['allow_redirects' => false])
                ->post(rtrim($c['base_url'], '/').$c['token_endpoint'], ['grant_type' => 'client_credentials', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret']])->throw()->json();
            $this->openverseToken = $token['access_token'] ?? throw new RuntimeException('Missing Openverse token');
            $this->openverseTokenExpiresAt = microtime(true) + max(0, ($token['expires_in'] ?? 900) - 30);
        }
        for ($try = 0; ; $try++) {
            $this->record($source, 'api_requests');
            if ($try) {
                $this->record($source, 'retries');
            }
            $this->throttle($source);
            try {
                $request = Http::withUserAgent(config('demo_content.user_agent'))->acceptJson()->timeout($c['request_timeout'])
                    ->connectTimeout($c['connect_timeout'])->withOptions(['allow_redirects' => false]);
                if ($source === 'openverse' && $this->openverseToken) {
                    $request->withToken($this->openverseToken);
                }
                $r = $request->get($url, $params);
                if ($r->successful()) {
                    $data = $r->json() ?? [];
                    if (isset($data['error'])) {
                        $this->record($source, 'api_errors');
                        throw new RuntimeException('API returned an application error');
                    }
                    if ($endpoint === $c['search_endpoint']) {
                        $rows = match ($source) {
                            'met' => $data['objectIDs'] ?? [],'artic' => $data['data'] ?? [],'commons' => $data['query']['pages'] ?? [],'nasa' => $data['collection']['items'] ?? [],'openverse' => $data['results'] ?? [],'smithsonian' => $data['response']['rows'] ?? []
                        };
                        $this->record($source, 'api_candidates', count($rows));
                    }
                    if (count($this->cache) >= config('demo_content.metadata_cache_limit', 256)) {
                        array_shift($this->cache);
                    }
                    $this->cache[$cacheKey] = $data;

                    return $data;
                }
                if (! in_array($r->status(), [429, 500, 502, 503, 504]) || $try >= $c['retry_attempts']) {
                    $this->record($source, 'api_errors');
                    $r->throw();
                }
                if (! $r->failed()) {
                    throw new RuntimeException('API redirect rejected');
                }
                $wait = $r->header('Retry-After');
                $seconds = ctype_digit($wait ?? '') ? (int) $wait : ($wait ? max(0, strtotime($wait) - time()) : $c['retry_delay'] / 1000 * (2 ** $try));
                if ($seconds > 30) {
                    throw new RuntimeException('Source paused: Retry-After exceeds 30 seconds');
                }
                usleep((int) ($seconds * 1000000));
            } catch (ConnectionException $e) {
                $this->record($source, 'network_errors');
                if ($try >= $c['retry_attempts']) {
                    throw $e;
                }
                usleep((int) ($c['retry_delay'] * 1000 * (2 ** $try)));
            }
        }
    }
}
