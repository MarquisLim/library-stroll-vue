<?php

namespace App\Jobs;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Format\Video\X264;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProcessVideoPreviewJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected Media $media) {}

    public function handle()
    {
        $source = $this->media->getPath();

        $ffprobe = FFProbe::create([
            'ffprobe.binaries' => config('medialibrary.ffprobe_path'),
        ]);
        $duration = (float) $ffprobe->format($source)->get('duration');
        $start    = max(0, rand(0, max(0, floor($duration) - 3)));

        $tmpDir  = storage_path('media-library/temp');
        @mkdir($tmpDir, 0755, true);
        $tmpFile = "$tmpDir/preview_{$this->media->id}.mp4";

        $ffmpeg = FFMpeg::create([
            'ffmpeg.binaries'  => config('medialibrary.ffmpeg_path'),
            'ffprobe.binaries' => config('medialibrary.ffprobe_path'),
        ]);
        $video  = $ffmpeg->open($source);
        $video->filters()
            ->clip(TimeCode::fromSeconds($start), TimeCode::fromSeconds(6));

        $format = new X264('aac');
        $format->setKiloBitrate(300);

        $video->save($format, $tmpFile);

        $model = $this->media->model;
        $model->addMedia($tmpFile)
            ->usingName('preview')
            ->toMediaCollection('preview');

        @unlink($tmpFile);

        $this->media->setCustomProperty('duration', (int) round($duration));
        $this->media->save();
    }
}
