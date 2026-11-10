<?php

namespace Tests\Feature;

use App\Services\DemoContent\CommonsAdapter;
use App\Services\DemoContent\MediaUrlPolicy;
use App\Services\DemoContent\NasaAdapter;
use App\Services\DemoContent\OpenverseAdapter;
use App\Services\DemoContent\SmithsonianAdapter;
use App\Services\DemoContent\SourceHttp;
use App\Services\DemoContent\SourceRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DemoSourcesTest extends TestCase
{
    public function test_bootstrap_overrides_inherited_live_database_variables_before_laravel(): void
    {
        $program = <<<'PHP'
$_SERVER['APP_ENV'] = 'local';
$_SERVER['DB_CONNECTION'] = 'mysql';
$_SERVER['DB_DATABASE'] = 'live_catalog';
$_ENV['DB_URL'] = 'mysql://forbidden.invalid/live';
require $argv[1];
echo json_encode([getenv('APP_ENV'), $_SERVER['DB_CONNECTION'], $_SERVER['DB_DATABASE'], $_ENV['DB_URL']]);
PHP;
        $process = new Process([PHP_BINARY, '-r', $program, base_path('tests/bootstrap.php')]);
        $process->mustRun();
        $this->assertSame(['testing', 'sqlite', ':memory:', ''], json_decode($process->getOutput(), true));
        $this->assertSame('sqlite', config('database.default'));
    }

    public function test_smithsonian_accepts_only_explicit_media_cc0(): void
    {
        config(['demo_content.sources.smithsonian.api_key' => 'test-only']);
        Http::fake([
            '*/search*' => Http::response(['response' => ['rows' => [['id' => 'object-1']]]]),
            '*/content/*' => Http::response(['response' => ['id' => 'object-1', 'title' => 'Flowers', 'content' => ['descriptiveNonRepeating' => ['record_link' => 'https://www.si.edu/object/1', 'online_media' => ['media' => [['type' => 'Images', 'content' => 'https://ids.si.edu/x.jpg', 'usage' => ['access' => 'CC0']]]]], 'freetext' => ['name' => [['content' => 'Painter']], 'topic' => [['content' => 'flowers']]]]]]),
        ]);
        $items = iterator_to_array((new SmithsonianAdapter)->candidates('flowers', 'image'));
        $this->assertCount(1, $items);
        $this->assertSame('CC0', $items[0]->license);
        $this->assertSame('Painter', $items[0]->creator_name);
    }

    public function test_nasa_video_asset_retains_nasa_usage_license(): void
    {
        Http::fake(['*/search*' => Http::response(['collection' => ['items' => [['data' => [['nasa_id' => 'moon', 'title' => 'Moon', 'center' => 'NASA', 'keywords' => ['moon']]]]]]]),
            '*/asset/*' => Http::response(['collection' => ['items' => [['href' => 'https://images-assets.nasa.gov/moon.mp4']]]])]);
        $items = iterator_to_array((new NasaAdapter)->candidates('moon', 'video'));
        $this->assertSame('video', $items[0]->media_type);
        $this->assertSame('NASA Media Usage Guidelines', $items[0]->license);
        $this->assertStringEndsWith('.mp4', $items[0]->media_url);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['demo_content.sources.openverse.client_id' => null, 'demo_content.sources.openverse.client_secret' => null]);
        Http::preventStrayRequests();
        foreach (array_keys(config('demo_content.sources')) as $s) {
            config(["demo_content.sources.$s.max_pages" => 1, "demo_content.sources.$s.rate_limit" => 1000000, "demo_content.sources.$s.retry_delay" => 0]);
        }
    }

    public function test_commons_uses_file_metadata_instead_of_query_as_tags(): void
    {
        Http::fake(['commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [['pageid' => 5, 'title' => 'File:Flowers.jpg', 'categories' => [['title' => 'Category:Flowers']], 'imageinfo' => [['url' => 'https://upload.wikimedia.org/flowers.jpg', 'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Flowers.jpg', 'extmetadata' => ['Artist' => ['value' => '<b>Painter</b>'], 'LicenseShortName' => ['value' => 'CC BY 4.0'], 'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by/4.0/']]]]]]]])]);
        $items = iterator_to_array((new CommonsAdapter)->candidates('anything', 'image'));
        $this->assertCount(1, $items);
        $this->assertSame(['Flowers'], $items[0]->original_tags);
        $this->assertSame('Painter', $items[0]->creator_name);
        $this->assertTrue((bool) app(SourceRegistry::class)->allowed($items[0]));
        Http::assertSent(fn ($r) => $r['generator'] === 'search' && $r['iiprop'] === 'url|extmetadata|mime');
    }

    public function test_nasa_third_party_credit_is_not_assumed_public_domain(): void
    {
        Http::fake(['*/search*' => Http::response(['collection' => ['items' => [['data' => [['nasa_id' => 'x', 'title' => 'Moon', 'photographer' => 'Getty Images', 'keywords' => ['moon']]]]]]]), '*/asset/*' => Http::response(['collection' => ['items' => [['href' => 'https://images-assets.nasa.gov/x.jpg']]]])]);
        $items = iterator_to_array((new NasaAdapter)->candidates('moon', 'image'));
        $this->assertSame('unverified', $items[0]->license);
        $this->assertFalse((bool) app(SourceRegistry::class)->allowed($items[0]));
    }

    public function test_smithsonian_without_key_skips_without_http(): void
    {
        config(['demo_content.sources.smithsonian.api_key' => null]);
        $this->assertSame([], iterator_to_array((new SmithsonianAdapter)->candidates('flowers', 'image')));
        Http::assertNothingSent();
    }

    public function test_openverse_anonymous_metadata_does_not_bypass_media_host_policy(): void
    {
        Http::fake(['api.openverse.org/*' => Http::response(['results' => [['id' => 'x', 'title' => 'Flowers', 'url' => 'https://untrusted.example/x.jpg', 'foreign_landing_url' => 'https://example.org/x', 'license' => 'cc0', 'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/', 'creator' => 'Painter', 'tags' => [['name' => 'flowers']]]]])]);
        $items = iterator_to_array((new OpenverseAdapter)->candidates('flowers', 'image'));
        $this->assertSame('CC0', $items[0]->license);
        $this->expectException(\RuntimeException::class);
        app(MediaUrlPolicy::class)->options($items[0]->media_url, config('demo_content.sources.openverse.allowed_media_hosts'));
    }

    public function test_timeouts_have_bounded_retry_and_no_redirect_following(): void
    {
        config(['demo_content.sources.met.retry_attempts' => 1]);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('timeout');
        });
        try {
            app(SourceHttp::class)->get('met', '/test');
            $this->fail('Expected timeout');
        } catch (ConnectionException) {
            $this->assertSame(2, $calls);
        }
    }
}
