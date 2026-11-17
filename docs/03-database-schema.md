# Database and import

MySQL is the deployment database; SQLite is an additional fast test target. The local MySQL 8.4.11 test instance is separate from the legacy database and is not a Windows service.

Relational groups:

- Identity: users, profiles, sessions, reset tokens, MFA, roles/permissions/pivots, audit events.
- Editorial: articles, hierarchical categories, tags, media, revisions, profile/tag pivots, redirects and homepage sections.
- Discovery: Builders with consent/diaspora classification, startups/founders, opportunities and a disabled optional editorial-events listing.
- Reader/intake: bookmarks, reading history, references, nominations, story submissions, newsletter and idempotency records.
- Operations: YouTube channels/videos/runs, settings, campaigns, analytics, import mappings, database jobs/cache/locks.

Unique keys protect slugs, references, bookmarks, video IDs, revision versions, import mappings and idempotency scopes. Publication/edition/location/date indexes support public queries. A per-hour analytics uniqueness key prevents concurrent duplicate view counting. Foreign keys protect relations; dates are UTC and WAT scheduling is converted by the frontend.

Article writes lock rows, check If-Match, increment versions, snapshot relations and audit in a transaction. Intake/reference allocation is transactional. Public profiles and founder relations exclude unpublished or non-consenting people.

## Reviewed import

`php artisan builder:import-snapshot /private/reviewed.json` rehearses and rolls back. Add `--apply` only for a reviewed isolated staging import. The command refuses APP_ENV=production. Input: `{version:1, source:"immutable-export-id", records:[{entity,sourceId,data}]}`.

Supported eligible entities: users, categories, tags, builders, startups, articles, newsletter, bookmarks. Order dependencies first. Articles reference authorSourceId/categorySourceId/tagSourceIds/builderSourceIds/startupSourceIds; startup founders use founderSourceIds. Published articles require original publication dates. Published Builders require consent evidence. User bcrypt hashes may be preserved; unsupported hashes need a reviewed reset migration. No plaintext password, privileged role, MFA secret, session or token is imported. All imported users start as registered_user.

Each source/entity/sourceId has a checksum mapping. Exact retries replay; changed mapped records fail rather than overwrite. Invalid references roll back the whole batch. Tests cover rehearsal, replay, hash verification, canonical taxonomy, relationships and rollback. The importer sends no mail and never copies legacy SQL/schema or unrelated modules.

Real extraction, upload/media mapping and reconciliation remain pending the actual production inventory. Source/Git backups and fixture import tests cannot substitute for that gate.
