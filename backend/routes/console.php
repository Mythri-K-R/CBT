<?php

use App\Console\Commands\AutoSubmitExpiredTests;
use App\Console\Commands\ActivateScheduledTests;
use App\Console\Commands\CompleteEndedTests;
use App\Console\Commands\DeactivateExpiredLinks;
use App\Console\Commands\CheckSubscriptionExpiry;
use App\Console\Commands\AggregateAnalytics;
use Illuminate\Support\Facades\Schedule;

Schedule::command(AutoSubmitExpiredTests::class)->everyMinute()->withoutOverlapping();
Schedule::command(ActivateScheduledTests::class)->everyMinute()->withoutOverlapping();
Schedule::command(CompleteEndedTests::class)->everyMinute()->withoutOverlapping();
Schedule::command(DeactivateExpiredLinks::class)->hourly()->withoutOverlapping();
Schedule::command(CheckSubscriptionExpiry::class)->dailyAt('07:00')->withoutOverlapping();
Schedule::command(AggregateAnalytics::class)->dailyAt('02:00')->withoutOverlapping();
