<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('rpg:prune-combat-logs --hours=24 --batch=10000')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
