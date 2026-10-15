<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Parental consent housekeeping (BR-8FDF37). purge-revoked is the job that
// makes the "erased within 30 days of revocation" promise true.
Schedule::command('consent:purge-revoked')->dailyAt('03:15');
Schedule::command('consent:prune-codes')->dailyAt('03:30');

// AUM audit log retention (BR-BNNASY): drops rows older than
// config('audit.retention_days'). Named explicitly so no other model ever
// gets pruned by accident.
Schedule::command('model:prune', ['--model' => [\App\Models\AuditLog::class]])->dailyAt('03:45');

// Task difficulty calibration (BR-697NCA): the live path applies answers in
// arrival order; the nightly replay puts late offline batches and answers from
// app builds without profil_elo_before where they belong. Only while it is on.
Schedule::command('elo:recalibrate')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->when(fn () => (bool) config('elo.task_calibration'));

// AI Course Assistant sessions expire in minutes; sweep their R2 uploads often.
// (Sessions also prune opportunistically when a new one is issued.)
Schedule::command('generation:prune')->hourly();
