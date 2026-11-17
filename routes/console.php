<?php

declare(strict_types=1);

use App\Domain\Editorial\Workflow;
use App\Jobs\SyncYoutubeChannel;
use App\Models\YoutubeChannel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('builder:publish-due', function (Workflow $workflow): void {
    $this->info('Published '.$workflow->publishDue().' scheduled articles.');
});
Artisan::command('builder:youtube-sync', function (): void {
    YoutubeChannel::where('active', true)->each(fn ($c) => SyncYoutubeChannel::dispatch($c->id));
    $this->info('Active channel syncs queued.');
});
Schedule::command('builder:publish-due')->everyFiveMinutes()->withoutOverlapping(5);
Schedule::command('builder:youtube-sync')->everyFifteenMinutes()->withoutOverlapping(10);
Schedule::command('queue:prune-failed --hours=168')->daily()->withoutOverlapping(10);
Schedule::command('builder:purge-accounts')->daily()->withoutOverlapping(30);
Schedule::command('queue:work database --queue=mail,youtube,default --stop-when-empty --max-time=60 --timeout=180 --tries=3')->everyFiveMinutes()->withoutOverlapping(5);
Schedule::call(fn () => DB::table('idempotency_requests')->where('created_at', '<', now()->subDay())->delete())->daily()->name('prune-idempotency')->withoutOverlapping();
Schedule::call(fn () => DB::table('analytics_events')->where('created_at', '<', now()->subDays(90))->delete())->daily()->name('prune-analytics')->withoutOverlapping();
