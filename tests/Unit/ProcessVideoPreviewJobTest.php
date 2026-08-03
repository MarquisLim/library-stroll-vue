<?php

namespace Tests\Unit;

use App\Jobs\ProcessVideoPreviewJob;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class ProcessVideoPreviewJobTest extends TestCase
{
    #[Test]
    public function it_defines_bounded_retry_and_timeout_settings(): void
    {
        $job = new ProcessVideoPreviewJob(new Media);

        $this->assertSame(3, $job->tries);
        $this->assertSame(180, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertTrue($job->deleteWhenMissingModels);
        $this->assertSame([10, 30, 60], $job->backoff());
    }

    #[Test]
    public function it_reads_ffmpeg_binaries_from_the_media_library_config_namespace(): void
    {
        config([
            'media-library.ffmpeg_path' => 'configured-ffmpeg',
            'medialibrary.ffmpeg_path' => 'wrong-ffmpeg',
        ]);

        $method = new ReflectionMethod(ProcessVideoPreviewJob::class, 'configuredBinaryPath');
        $job = new ProcessVideoPreviewJob(new Media);

        $this->assertSame('configured-ffmpeg', $method->invoke($job, 'ffmpeg_path'));
    }

    #[Test]
    public function it_rejects_non_persisted_media_with_a_diagnostic_error(): void
    {
        $job = new ProcessVideoPreviewJob(new Media);

        try {
            $job->handle();
            $this->fail('The job did not reject non-persisted media.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Video preview processing failed for media []',
                $exception->getMessage()
            );
            $this->assertSame(
                'The source media has no persisted identifier.',
                $exception->getPrevious()?->getMessage()
            );
        }
    }

    #[Test]
    public function it_logs_context_when_all_attempts_fail(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with(
                'Video preview job failed permanently.',
                [
                    'media_id' => null,
                    'attempts' => 1,
                    'exception' => 'FFmpeg exited with code 1.',
                    'exception_class' => RuntimeException::class,
                ]
            );

        (new ProcessVideoPreviewJob(new Media))
            ->failed(new RuntimeException('FFmpeg exited with code 1.'));
    }
}
