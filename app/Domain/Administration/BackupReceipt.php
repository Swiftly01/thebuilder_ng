<?php

declare(strict_types=1);

namespace App\Domain\Administration;

/** Verifies operator-produced evidence; it never performs a backup or restore. */
final class BackupReceipt
{
    /** @param array<string, mixed> $database */
    public function verify(string $path, string $releaseDirectory, string $sharedDirectory, array $database): void
    {
        $release = realpath($releaseDirectory);
        $shared = realpath($sharedDirectory);
        $file = realpath($path);
        if (! $release || ! $shared || ! $file || ! $this->inside($file, $shared.DIRECTORY_SEPARATOR.'backups') || filesize($file) > 65536) {
            throw new \RuntimeException('receipt_location_invalid');
        }
        $this->privateFile($file);
        $receipt = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($receipt) || ($receipt['version'] ?? null) !== 1) {
            throw new \RuntimeException('receipt_version_invalid');
        }
        $this->recent($receipt['created_at'] ?? null);
        $restore = $receipt['restore'] ?? [];
        if (($restore['verified'] ?? null) !== true || ! is_string($restore['verified_by'] ?? null) || trim($restore['verified_by']) === '') {
            throw new \RuntimeException('restore_not_verified');
        }
        $this->recent($restore['verified_at'] ?? null);
        $manifest = $release.DIRECTORY_SEPARATOR.'manifest.sha256.json';
        if (! is_file($manifest) || ($receipt['release']['id'] ?? null) !== basename($release) || ! $this->matchesHash(hash_file('sha256', $manifest), $receipt['release']['manifest_sha256'] ?? null)) {
            throw new \RuntimeException('receipt_release_mismatch');
        }
        $entries = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($entries) || $entries === []) {
            throw new \RuntimeException('release_manifest_invalid');
        }
        foreach ($entries as $entry) {
            $relative = $entry['path'] ?? '';
            if (! is_string($relative) || $relative === '' || str_contains($relative, '\\') || str_contains($relative, ':') || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) {
                throw new \RuntimeException('release_manifest_invalid');
            }
            $source = realpath($release.DIRECTORY_SEPARATOR.$relative);
            if (! $source || ! is_file($source) || ! $this->inside($source, $release) || ! $this->matchesHash(hash_file('sha256', $source), $entry['sha256'] ?? null)) {
                throw new \RuntimeException('release_file_mismatch');
            }
        }
        foreach (['host', 'port', 'database', 'username'] as $key) {
            if ((string) ($receipt['database'][$key] ?? '') !== (string) ($database[$key] ?? '') || (string) ($database[$key] ?? '') === '') {
                throw new \RuntimeException('receipt_database_mismatch');
            }
        }
        foreach (['database_dump', 'uploads_archive'] as $key) {
            $entry = $receipt[$key] ?? [];
            $backup = is_string($entry['path'] ?? null) ? realpath($shared.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.$entry['path']) : false;
            if (! $backup || ! is_file($backup) || filesize($backup) === 0 || ! $this->inside($backup, $shared.DIRECTORY_SEPARATOR.'backups')) {
                throw new \RuntimeException('backup_location_invalid');
            }
            $this->privateFile($backup);
            $hash = hash_file('sha256', $backup);
            if (! $this->matchesHash($hash, $entry['sha256'] ?? null) || ! $this->matchesHash($hash, $restore[$key.'_sha256'] ?? null)) {
                throw new \RuntimeException('backup_hash_mismatch');
            }
        }
    }

    private function matchesHash(string $actual, mixed $expected): bool
    {
        return is_string($expected) && preg_match('/^[a-fA-F0-9]{64}$/D', $expected) && hash_equals($actual, strtolower($expected));
    }

    private function inside(string $file, string $root): bool
    {
        $realRoot = realpath($root);

        return $realRoot !== false && str_starts_with($file, $realRoot.DIRECTORY_SEPARATOR) && ! str_contains(str_replace('\\', '/', strtolower($file)), '/public_html/');
    }

    private function privateFile(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0077) !== 0) {
            throw new \RuntimeException('backup_permissions_invalid');
        }
    }

    private function recent(mixed $value): void
    {
        $at = is_string($value) ? strtotime($value) : false;
        if ($at === false || $at > time() + 60 || $at < time() - 86400) {
            throw new \RuntimeException('receipt_expired_or_invalid');
        }
    }
}
