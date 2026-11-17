<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Administration\SnapshotImport;
use Illuminate\Console\Command;

final class ImportSnapshot extends Command
{
    protected $signature = 'builder:import-snapshot {path : Private normalized JSON snapshot} {--apply : Commit the validated import; otherwise roll it back}';

    protected $description = 'Validate and rehearse an idempotent import into an isolated non-production database.';

    public function handle(SnapshotImport $importer): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 52428800) {
            $this->error('A readable private snapshot under 50 MB is required.');

            return self::FAILURE;
        }
        try {
            $snapshot = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($snapshot)) {
                throw new \RuntimeException('The snapshot must be an object.');
            }
            $counts = $importer->run($snapshot, (bool) $this->option('apply'));
            $this->info(($this->option('apply') ? 'Applied' : 'Rehearsed and rolled back').': '.$counts['created'].' records, '.$counts['replayed'].' replayed.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            report($error);
            $this->error('Import failed and rolled back. Review private application logs and mapping validation.');

            return self::FAILURE;
        }
    }
}
