# The Builder API — clean Laravel 12 candidate

Fresh Laravel 12/MySQL implementation staged beside the preserved backend. All domain endpoints use `/api/v1`; Sanctum cookie sessions, verified email, role permissions and privileged MFA protect mutations. No production account or password is seeded.

This is a release candidate, not an activated production backend. Do not replace `/backend` until real database/upload preservation, reviewed import reconciliation and staging acceptance pass. See `docs/12-production-readiness.md` and `docs/09-stellar-deployment.md` for outstanding gates.

## Local setup

Use PHP 8.2+ with PDO MySQL, GD/WebP, curl, mbstring, DOM, fileinfo and OpenSSL. Install locked Composer dependencies, copy `.env.example` to a private `.env`, generate an application key, configure a dedicated MySQL database, then run `php artisan migrate` and `php artisan db:seed`. Use the matching frontend configuration and one consistent localhost hostname for cookies.

Production settings are documented in `deploy/.env.production.example`. Never put secrets in `NEXT_PUBLIC_*` variables or commit `.env`. The frontend requires a verified Node SSR hosting destination.

Set the same private `BUILDER_SSR_SECRET` (at least 32 characters, generated randomly) in the frontend server and API environments. Only HMAC-authenticated, stateless public GET routes use the separate bounded SSR budget. Unsigned browsers retain their IP limit; no arbitrary visitor-IP header is trusted. Keep both server clocks synchronized; signatures expire after 30 seconds. `BUILDER_SSR_REQUESTS_PER_MINUTE` defaults to 6000 and must be sized against real hosting capacity.

## Quality checks

```sh
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpunit
php tools/export-openapi.php --check
composer audit --locked
```

Default tests use disposable in-memory SQLite. MySQL acceptance must use a separate explicitly named test database; tests refresh its schema. Never point tests at a shared or production database. `docs/openapi.json` is the generated contract; copy the reviewed export into the frontend's `contracts/openapi.json` when changing it.

## Operations

`builder:release-check` validates configuration and migrations; it does not certify external services. `builder:import-snapshot` defaults to a rollback rehearsal and refuses production execution. `builder:grant-admin` promotes an existing verified account after operator confirmation. Scheduler tasks and bounded database queue workers are defined for the reviewed five-minute shared-hosting cadence.

`tools/package-release.ps1` creates a new separate release directory with a positive source list, locked production dependencies and a SHA-256 manifest. `deploy/prepare-release.sh` prepares an authorized host release without activating its document root. Backups and real rollback verification remain prerequisites for activation.

Preparation now invokes `builder:release-check --preflight` before migrations/seeding. It requires a fresh private receipt in `shared/backups`, bound to the exact target database, release manifest and actual database/upload archive hashes, plus operator-recorded restore verification. Use `deploy/backup-receipt.example.json` as a schema, never as proof that a backup/restore happened. On Linux, receipts/backups must have private file permissions. Stale/mismatched receipts, changed packaged files or invalid production settings fail before database writes. The post-migration check still verifies pending migrations, official channel and public media link. Fresh-release bootstrap caches must be empty to prevent stale configuration from bypassing preflight. A failed one-shot preparation needs operator review; do not rerun blindly or delete existing stored data.
