# API contract

The source is `app/Domain/Administration/ApiContract.php`, registered routes, FormRequests and inline validation declarations in the clean candidate. Generate `docs/openapi.json` with `php tools/export-openapi.php`; `--check` rejects drift. Copy the generated contract into frontend/contracts/openapi.json for its independent Git/CI project before a coordinated release.

OpenAPI records methods, path/query/header parameters, mutation fields, complete Laravel validation extensions including nested fields, session authentication, middleware/permissions/MFA, resource schemas, errors, pagination, frontend mappings and side effects. Detailed administration documents allow additional response fields; that never permits undeclared mutation mass assignment.

Public APIs cover home/articles/taxonomy, Builders/startups/opportunities/videos, search/suggestions, public settings, sitemap and ad placements. Identity and reader APIs cover sessions/verification/reset/MFA, profiles, interests, bookmarks/history and submissions. Console APIs cover publishing/revisions, profiles/media, intake review/drafts, homepage, taxonomy, users/roles, settings, audit, analytics, campaigns and YouTube. No old domain aliases exist.

Article updates/transitions/restores require current numeric If-Match: missing is 428, stale is 412. Nominations/newsletter/submissions require an 8–120 character Idempotency-Key. Exact retries replay success; changed payloads return 409; retention is 24 hours. Fresh bot tokens do not change the business-payload fingerprint.

Collections use Resource data/links/meta or native Laravel paginator fields; the frontend normalizes both. Errors use type/title/status/detail/requestId and 422 field errors. `/up` and `/sanctum/csrf-cookie` are infrastructure outside the domain base.

Swagger Parser validates the document; checks also enforce unique operation IDs, authorization/MFA metadata, error responses and frontend mappings. Final totals appear in the testing report.
