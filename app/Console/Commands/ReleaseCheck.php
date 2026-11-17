<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Administration\BackupReceipt;
use App\Models\YoutubeChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReleaseCheck extends Command
{
    protected $signature = 'builder:release-check {--preflight : Validate configuration and backup evidence before database writes} {--backup-receipt=} {--release-dir=} {--shared-dir=}';

    protected $description = 'Check production configuration without displaying secrets or claiming live delivery verification.';

    public function handle(): int
    {
        $frontend = config('builder.frontend_url');
        $frontendHost = parse_url($frontend, PHP_URL_HOST);
        $apiHost = parse_url(config('app.url'), PHP_URL_HOST);
        $domain = (string) config('builder.site_domain');
        $checks = [
            'production environment' => app()->environment('production'),
            'debug disabled' => ! config('app.debug'),
            'application key configured' => strlen((string) config('app.key')) >= 32,
            'HTTPS frontend and API' => str_starts_with($frontend, 'https://') && str_starts_with(config('app.url'), 'https://'),
            'approved frontend domain' => in_array($frontendHost, [$domain, 'www.'.$domain], true),
            'same-site API' => is_string($apiHost) && ($apiHost === $domain || str_ends_with($apiHost, '.'.$domain)),
            'MySQL database' => config('database.default') === 'mysql',
            'database cache' => config('cache.default') === 'database',
            'database queue' => config('queue.default') === 'database',
            'database sessions' => config('session.driver') === 'database',
            'secure session cookies' => (bool) config('session.secure') && config('session.http_only') && config('session.same_site') === 'lax',
            'shared session domain' => in_array(config('session.domain'), ['.'.$domain, $domain], true),
            'explicit credentialed CORS' => in_array($frontend, config('cors.allowed_origins'), true) && ! in_array('*', config('cors.allowed_origins'), true) && config('cors.supports_credentials'),
            'stateful frontend' => in_array($frontendHost, config('sanctum.stateful'), true),
            'SMTP configured' => config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host')) && filled(config('mail.from.address')),
            'Turnstile configured' => filled(config('services.turnstile.secret')) && in_array($frontendHost, config('services.turnstile.hostnames'), true),
            'public SSR signing configured' => strlen((string) config('builder.ssr_secret')) >= 32 && (int) config('builder.ssr_requests_per_minute') >= 120,
            'queue retry budget' => (int) config('queue.connections.database.retry_after') > 180,
            'YouTube key configured' => filled(config('builder.youtube_key')),
            'writable private storage' => is_writable(storage_path('framework')) && is_writable(storage_path('logs')),
            'daily logs with retention' => config('logging.default') === 'daily' && (int) config('logging.channels.daily.days') > 0 && (int) config('logging.channels.daily.days') <= 30,
        ];
        if ($this->option('preflight')) {
            if (in_array(false, $checks, true)) {
                foreach ($checks as $label => $passes) {
                    $this->line(($passes ? 'PASS ' : 'FAIL ').$label);
                }
                $this->error('Preflight rejected configuration before database access or writes.');

                return self::FAILURE;
            }
            try {
                app(BackupReceipt::class)->verify((string) $this->option('backup-receipt'), (string) $this->option('release-dir'), (string) $this->option('shared-dir'), config('database.connections.mysql'));
                DB::connection()->getPdo();
                $this->info('PASS production preflight, connected target and matching private backup/restore evidence. No migrations or seeds were run.');

                return self::SUCCESS;
            } catch (\Throwable) {
                $this->error('FAIL backup/restore evidence or target connection. No migrations or seeds were run.');

                return self::FAILURE;
            }
        }
        $checks['public media link'] = is_link(public_path('storage'));
        try {
            DB::connection()->getPdo();
            $checks['database connected'] = true;
            $checks['active official YouTube channel'] = YoutubeChannel::where('active', true)->exists();
            $checks['no pending migrations'] = count(array_diff(array_keys(app('migrator')->getMigrationFiles(database_path('migrations'))), app('migration.repository')->getRan())) === 0;
        } catch (\Throwable) {
            $checks['database connected'] = false;
        }
        foreach ($checks as $label => $passes) {
            $this->line(($passes ? 'PASS ' : 'FAIL ').$label);
        }
        $this->comment('Configuration checks do not verify DNS, actual cPanel PHP/cron limits, SMTP delivery, provider quota, backup restore or cross-origin browser behavior. Record that evidence before release.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
