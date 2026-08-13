<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('system-health:redis')
    ->everyMinute()
    ->withoutOverlapping(2);
Schedule::command('rocketchat-audit:sync --limit=100')
    ->everyMinute()
    ->withoutOverlapping(5);
Schedule::command('freshdesk-spool:recover --limit=500')
    ->everyMinute()
    ->withoutOverlapping(5);
Schedule::command('ticket-events:recover-processing --limit=500')
    ->everyMinute()
    ->withoutOverlapping(5);
Schedule::command('sla-overdue:scan --limit=500')
    ->everyMinute()
    ->withoutOverlapping(5);
Schedule::command('freshdesk-spool:gc --limit=1000')
    ->everyMinute()
    ->withoutOverlapping(5);
Schedule::command('rocketchat-audit:prune')
    ->dailyAt('02:30')
    ->withoutOverlapping(60);
