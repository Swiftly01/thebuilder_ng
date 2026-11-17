<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Administration\BackupReceipt;
use PHPUnit\Framework\TestCase;

class BackupReceiptTest extends TestCase
{
    private string $root;

    /** @var array<string, mixed> */
    private array $receipt;

    /** @var array<string, string> */
    private array $database = ['host' => 'localhost', 'port' => '3306', 'database' => 'builder_target', 'username' => 'builder_operator'];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'builder-receipt-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/release', 0700, true);
        mkdir($this->root.'/shared/backups', 0700, true);
        file_put_contents($this->root.'/release/source.php', '<?php // Disposable fixture');
        file_put_contents($this->root.'/release/manifest.sha256.json', json_encode([['path' => 'source.php', 'sha256' => strtoupper(hash_file('sha256', $this->root.'/release/source.php'))]], JSON_THROW_ON_ERROR));
        foreach (['database.sql.gz', 'uploads.tar.gz'] as $file) {
            file_put_contents($this->root.'/shared/backups/'.$file, 'Disposable '.$file);
            chmod($this->root.'/shared/backups/'.$file, 0600);
        }
        $db = hash_file('sha256', $this->root.'/shared/backups/database.sql.gz');
        $uploads = hash_file('sha256', $this->root.'/shared/backups/uploads.tar.gz');
        $this->receipt = ['version' => 1, 'created_at' => gmdate(DATE_ATOM), 'release' => ['id' => 'release', 'manifest_sha256' => hash_file('sha256', $this->root.'/release/manifest.sha256.json')], 'database' => $this->database, 'database_dump' => ['path' => 'database.sql.gz', 'sha256' => $db], 'uploads_archive' => ['path' => 'uploads.tar.gz', 'sha256' => $uploads], 'restore' => ['verified' => true, 'verified_by' => 'Isolated test operator', 'verified_at' => gmdate(DATE_ATOM), 'database_dump_sha256' => $db, 'uploads_archive_sha256' => $uploads]];
    }

    private function verify(): void
    {
        $path = $this->root.'/shared/backups/receipt.json';
        file_put_contents($path, json_encode($this->receipt, JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        (new BackupReceipt)->verify($path, $this->root.'/release', $this->root.'/shared', $this->database);
    }

    public function test_matching_fresh_private_backups_and_restored_hashes_are_accepted(): void
    {
        $this->verify();
        $this->receipt['release']['manifest_sha256'] = strtoupper($this->receipt['release']['manifest_sha256']);
        foreach (['database_dump', 'uploads_archive'] as $key) {
            $this->receipt[$key]['sha256'] = strtoupper($this->receipt[$key]['sha256']);
            $this->receipt['restore'][$key.'_sha256'] = strtoupper($this->receipt['restore'][$key.'_sha256']);
        }
        $this->verify();
        $this->addToAssertionCount(1);
    }

    public function test_stale_receipt_is_rejected(): void
    {
        $this->receipt['created_at'] = gmdate(DATE_ATOM, time() - 90000);
        $this->expectExceptionMessage('receipt_expired_or_invalid');
        $this->verify();
    }

    public function test_wrong_database_is_rejected(): void
    {
        $this->receipt['database']['database'] = 'another_database';
        $this->expectExceptionMessage('receipt_database_mismatch');
        $this->verify();
    }

    public function test_unverified_restore_is_rejected(): void
    {
        $this->receipt['restore']['verified'] = false;
        $this->expectExceptionMessage('restore_not_verified');
        $this->verify();
    }

    public function test_tampered_backup_is_rejected(): void
    {
        file_put_contents($this->root.'/shared/backups/database.sql.gz', 'Changed after verification');
        $this->expectExceptionMessage('backup_hash_mismatch');
        $this->verify();
    }

    public function test_changed_release_is_rejected(): void
    {
        file_put_contents($this->root.'/release/source.php', 'Changed release');
        $this->expectExceptionMessage('release_file_mismatch');
        $this->verify();
    }

    public function test_backup_path_escape_is_rejected(): void
    {
        $this->receipt['database_dump']['path'] = '../../release/source.php';
        $this->expectExceptionMessage('backup_location_invalid');
        $this->verify();
    }

    protected function tearDown(): void
    {
        foreach (['shared/backups/receipt.json', 'shared/backups/database.sql.gz', 'shared/backups/uploads.tar.gz', 'release/manifest.sha256.json', 'release/source.php'] as $path) {
            if (is_file($this->root.'/'.$path)) {
                unlink($this->root.'/'.$path);
            }
        }
        foreach (['shared/backups', 'shared', 'release', ''] as $directory) {
            rmdir($this->root.'/'.$directory);
        }
    }
}
