<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('freshdesk-spool:dispatch --limit=250')->everySecond();
Schedule::command('ticket-logic-outbox:dispatch --limit=250')->everySecond();
Schedule::command('freshdesk-outbound:dispatch --limit=100')->everySecond();
Schedule::command('system-health:redis')->everyThirtySeconds();
Schedule::command('rocketchat-audit:sync --limit=100')->everyMinute();
Schedule::command('freshdesk-spool:recover --limit=500')->everyMinute();
Schedule::command('ticket-events:recover-processing --limit=500')->everyMinute();
Schedule::command('freshdesk-spool:gc --limit=1000')->hourly();
Schedule::command('rocketchat-audit:prune')->dailyAt('02:30');
