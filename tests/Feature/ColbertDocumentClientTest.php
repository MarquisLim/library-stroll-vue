<?php

namespace Tests\Feature;

use App\Services\AiService\ColbertDocumentClient;
use App\Services\AiService\ColbertDocumentException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ColbertDocumentClientTest extends TestCase
{
    private const URL = 'http://colbert:8001/api/v1/documents/441101';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'ai.colbert_sync.enabled' => true,
            'ai.colbert_sync.url' => 'http://colbert:8001',
            'ai.colbert_sync.api_key' => 'synthetic-test-key',
            'ai.colbert_sync.api_key_file' => null,
            'ai.colbert_sync.connect_timeout' => 0.25,
            'ai.colbert_sync.timeout' => 2.0,
            'ai.api_key' => 'synthetic-main-api-key',
        ]);
    }

    public function test_document_methods_use_separate_auth_and_correct_preconditions(): void
    {
        $hash = str_repeat('a', 64);
        $version = str_repeat('b', 32);
        $live = ['artwork_id' => 441101, 'exists' => true, 'content_hash' => $hash, 'version' => $version];
        $deleted = ['artwork_id' => 441101, 'exists' => false, 'content_hash' => null, 'version' => str_repeat('c', 32)];
        Http::fake(function ($request, $options) use ($live, $deleted, $hash, $version) {
            $this->assertTrue($request->hasHeader('X-ColBERT-Sync-Key', 'synthetic-test-key'));
            $this->assertFalse($request->hasHeader('X-AI-Service-Key'));
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(0.25, $options['connect_timeout']);
            $this->assertSame(2.0, $options['timeout']);

            if ($request->method() === 'PUT') {
                $this->assertSame(self::URL, $request->url());
                $this->assertSame([
                    'searchable_text' => 'new work',
                    'expected_previous_hash' => null,
                    'expected_previous_version' => null,
                ], $request->data());

                return Http::response($live + ['status' => 'created']);
            }

            if ($request->method() === 'DELETE') {
                $this->assertSame(self::URL.'?'.http_build_query([
                    'expected_previous_hash' => $hash,
                    'expected_previous_version' => $version,
                ]), $request->url());

                return Http::response($deleted + ['status' => 'deleted']);
            }

            $this->assertSame('GET', $request->method());
            $this->assertSame(self::URL, $request->url());

            return Http::response($live + ['token_count' => 30]);
        });

        $client = app(ColbertDocumentClient::class);
        $this->assertSame($live, $client->get(441101));
        $this->assertSame($live + ['status' => 'created'], $client->upsert(441101, 'new work'));
        $this->assertSame($deleted + ['status' => 'deleted'], $client->delete(441101, $hash, $version));
        Http::assertSentCount(3);
    }

    #[DataProvider('responseStatuses')]
    public function test_error_responses_keep_status_and_hide_body_without_retry(int $status): void
    {
        Http::fake([self::URL => Http::response(['detail' => 'synthetic-test-key'], $status, ['Location' => 'http://other.test'])]);

        try {
            app(ColbertDocumentClient::class)->upsert(441101, 'temporary text');
            $this->fail('Expected document request failure.');
        } catch (ColbertDocumentException $error) {
            $this->assertSame($status, $error->getCode());
            $this->assertStringNotContainsString('synthetic-test-key', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        Http::assertSentCount(1);
    }

    public static function responseStatuses(): array
    {
        return [[401], [403], [409], [503], [307]];
    }

    public function test_connection_error_is_sanitized_and_a_mutation_is_never_retried(): void
    {
        $attempts = 0;
        Http::fake([self::URL => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('transport error with synthetic-test-key');
        }]);

        try {
            app(ColbertDocumentClient::class)->upsert(441101, 'new work');
            $this->fail('Expected connection failure.');
        } catch (ColbertDocumentException $error) {
            $this->assertSame(0, $error->getCode());
            $this->assertStringNotContainsString('synthetic-test-key', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame(1, $attempts);
    }

    public function test_disabled_or_unconfigured_client_does_not_send_requests(): void
    {
        Http::fake();
        $client = app(ColbertDocumentClient::class);
        foreach ([
            ['ai.colbert_sync.enabled' => false],
            ['ai.colbert_sync.enabled' => true, 'ai.colbert_sync.api_key' => ' '],
        ] as $settings) {
            config($settings);
            try {
                $client->get(441101);
                $this->fail('Expected invalid client configuration.');
            } catch (LogicException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_unknown_mutation_status_is_rejected(): void
    {
        Http::fake([self::URL => Http::response([
            'artwork_id' => 441101,
            'exists' => true,
            'content_hash' => str_repeat('a', 64),
            'version' => str_repeat('b', 32),
            'status' => 'unexpected',
        ])]);
        $this->expectException(ColbertDocumentException::class);
        $this->expectExceptionCode(502);

        app(ColbertDocumentClient::class)->upsert(441101, 'new work');
    }
}
