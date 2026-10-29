<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('campaigns:dispatch-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('contacts:score-churn')->dailyAt('03:00');
Schedule::command('mktly:embeddings:rebuild')->hourly()->withoutOverlapping();
