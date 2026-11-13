<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('cod:tracking-poll --limit=25')
    ->everyFifteenMinutes()
    ->withoutOverlapping();


// BOARDRIDERS BLOCK 43 — COD case alert monitor.

Schedule::command(
    'cod:case-alerts --limit=100 --enqueue-limit=100'
)
    ->everyMinute()
    ->withoutOverlapping(10);
