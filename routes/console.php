<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('ai:reconcile --limit=25')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => config('ai.enabled'));
