<?php

use App\Support\AppSettings;
use App\Support\TermArchive;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('omnivote:backup')
    ->daily()
    ->when(function () {
        return (bool) AppSettings::backup('autoBackup', true);
    });

// Belt-and-braces: even if no admin opens the panel on the exact day the term
// ends, the daily tick archives the expired term's winners. (The admin-access
// checks in AdminDashboardController/SettingsController are the realtime path.)
Schedule::call(function () {
    TermArchive::runIfDue();
})->dailyAt('00:05')->name('omnivote:archive-expired-term');
