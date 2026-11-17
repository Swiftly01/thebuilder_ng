# Preservation and rollback

Updated 15 October 2026 (Africa/Lagos). No production deployment or backend-directory cutover has occurred.

The root is not a Git repository; backend and frontend are separate repositories. Temporary indexes and commit-tree preserved user changes without resetting their indexes or worktrees.

| Repository | Original HEAD | Preserved snapshot | Tag |
|---|---|---|---|
| backend | 06990c3b7d3e58f50eacb0db6533e0d705b5193a | 4bb64883c401dd51c6a5d511bf06519166f1f278 | pre-clean-laravel12-replatform-20261014-backend |
| frontend | f73c897edf995e6b697434110b22ad58b2bc6dbd | b0e03e1fde609e83993e6bf64d1b78c12b20e27d | pre-clean-laravel12-replatform-20261014-frontend |

Verified Git bundles, git-references.json, and the 862-file filesystem SHA-256 manifest are in `.replatform/archives/pre-clean-20261014`. Regenerable dependencies, .next, .git and the old 1.3 GB migration archive were excluded; that older archive remains intact in the old backend. An initial incomplete archive was removed after disk exhaustion and is not a recovery point.

The archive includes private environments and uploads. Never commit it, package it for deployment or place it under a web root. Encrypt off-machine backups.

No production database backup has been obtained or verified. The legacy configured local MySQL port was unavailable and was not modified. Git/source preservation is not a database backup.

An independent MySQL 8.4.11 instance on 127.0.0.1:3307 contains generated test/preview/restore fixtures only. A complete reset/fresh migration and mysqldump/restore rehearsal succeeded. All 46 table counts, fixture relationships and foreign-key rejection matched. This is local recovery evidence, not production-data preservation.

Retain `/backend` until the real database/uploads are privately backed up and restored in staging, eligible imports are reviewed, counts/relations/password hashes/consent/URLs are reconciled, and coordinated frontend/backend acceptance passes. The clean candidate remains `.replatform/laravel12-backend` until those gates pass. Never overlay old migrations or vendor.

Before production activation: explicitly authorize deployment, freeze writes, take final verified backups, preserve APP_KEY/environment, and record matching release hashes. Rollback must restore matching frontend/API versions; the new frontend cannot simply point at an incompatible old API.

After live writes, freeze and preserve the new database/uploads before choosing roll-forward or rollback. Reconcile the write delta explicitly. Restoring an old dump alone would discard new records. Real production import/reverse-delta/rollback rehearsal remains pending.
