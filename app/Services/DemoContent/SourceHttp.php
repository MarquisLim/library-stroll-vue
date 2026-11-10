<?php

namespace App\Services\DemoContent;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SourceHttp
{
    private array $last = [];

    private ?string $openverseToken = null;

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
        $url = rtrim($c['base_url'], '/').'/'.ltrim($endpoint, '/');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('API must use HTTPS');
        }
        if ($source === 'openverse' && $c['client_id'] && $c['client_secret'] && ! $this->openverseToken) {
            $token = Http::asForm()->timeout($c['request_timeout'])->connectTimeout($c['connect_timeout'])->withOptions(['allow_redirects' => false])
                ->post(rtrim($c['base_url'], '/').$c['token_endpoint'], ['grant_type' => 'client_credentials', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret']])->throw()->json();
            $this->openverseToken = $token['access_token'] ?? throw new RuntimeException('Missing Openverse token');
        }
        for ($try = 0; ; $try++) {
            $this->throttle($source);
            try {
                $request = Http::withUserAgent(config('demo_content.user_agent'))->acceptJson()->timeout($c['request_timeout'])
                    ->connectTimeout($c['connect_timeout'])->withOptions(['allow_redirects' => false]);
                if ($source === 'openverse' && $this->openverseToken) {
                    $request->withToken($this->openverseToken);
                }
                $r = $request->get($url, $params);
                if ($r->successful()) {
                    return $r->json() ?? [];
                }
                if (! in_array($r->status(), [429, 500, 502, 503, 504]) || $try >= $c['retry_attempts']) {
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
                if ($try >= $c['retry_attempts']) {
                    throw $e;
                }
                usleep((int) ($c['retry_delay'] * 1000 * (2 ** $try)));
            }
        }
    }
}
