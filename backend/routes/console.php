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


// BLOCK 47 — scheduled monitoring of sealed COD reports.
Schedule::command('cod:daily-monitor --limit=50')
    ->everyFiveMinutes()
    ->withoutOverlapping(15);
