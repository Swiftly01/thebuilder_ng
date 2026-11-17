# Verification results

16 October 2026, Africa/Lagos. These are local candidate checks, not hosted production acceptance. The candidate is `.replatform/laravel12-backend`; the previous `/backend` has not been replaced.

| Check | Result and evidence |
|---|---|
| SQLite regression | 63 tests, 730 assertions; candidate `test-results.txt` |
| MySQL regression | 63 tests, 730 assertions on isolated MySQL 8.4.11; candidate `mysql-test-results.txt` |
| PHP static analysis | Larastan level 6, zero errors, no baseline; candidate `phpstan-results.json` |
| PHP style/configuration | Pint and Composer strict validation pass |
| Composer security | Zero advisories and abandoned dependencies; candidate `composer-audit.json` |
| API contract | Swagger Parser validates 84 paths / 105 operations, unique operation IDs, console auth/MFA/permission metadata and frontend mappings; export drift check passes |
| Frontend | TypeScript, theme/settings/authentication checks and optimized `next build --webpack` pass; `build-replatform-qa-fixes.log` |
| Theme | 93 semantic tokens, 12 first-paint scenarios including blocked storage, 41 public route files / 135 reachable sources, 34 defined referenced tokens, 33 contrast checks |
| Browser | Live homepage/search suggestions, dark desktop and 390px mobile layout, mobile account drawer, light-theme opportunity deadline selection; no horizontal overflow or logged errors in inspected flows |
| Real HTTP | All 14 grouped journeys pass; `.replatform/runtime/http-integration-results.json` |
| Schema recovery | All 12 migrations reset/reapply; 46 tables; dump restored into a new isolated schema; table counts, full column definitions, fixture joins and foreign-key rejection pass; runtime `schema-rehearsal.json`, `restore-results.json`, `backup-sha256.json` |
| Production dependency audit | Frontend `audit-production.json`: zero vulnerabilities |
| Development tooling audit | Frontend `audit-tooling.json`: 7 high findings deriving from unpatched `braces` through build/lint tooling; not a zero-finding report |
| ESLint | Zero errors, 84 warnings for unused prototype helpers, broad types, images/anchors, punctuation and synchronous effect state; retain `lint-replatform.json` rather than suppressing these globally |
| Release packaging | Positive source list, locked production dependencies, host platform checks, private-file exclusion and SHA-256 manifest; independent receipt in runtime `package-verification.json` |
| Outage/recovery and SEO | Controlled API outage renders HTTP 503; restored API/frontend return 200; sitemap XML and robots rules pass; anonymous console gallery redirects to sign-in; invalid region returns 404; runtime `outage-results.json`, `final-smoke-results.json` |
| Preserved backend | All 649 backend entries match their pre-clean archive hashes; runtime `legacy-preservation-check.json` |

The HTTP script exercises actual CSRF rejection; registration/HttpOnly sessions/client-role rejection/unverified gating; queued verification mail and signed URL consumption; onboarding/profile; bookmarks/history/removal; privileged MFA challenge; create/review/approve/publish with HTML sanitization and authorization; publication rendered by the built frontend; nomination idempotency/review; role changes and audit; private redirects/real 404/CSP hash; logout invalidation. It is guarded to local preview configuration, uses generated `.test` fixtures, and delivers mail only to the private local log.

Feature tests additionally cover RFC TOTP, one-use recovery, content policy ownership, optimistic concurrency, revisions/scheduling, profile consent and founder privacy, diaspora classification, homepage pin order/draft exclusion, consented atomic metrics, opportunity expiry/filters, import rollback/replay/broken references, account retention, taxonomy cycles, media re-encoding/in-use deletion, and mocked official-provider sync/reconciliation/error handling.

Failures fixed during verification included a blank local mail sender, quoted-printable verification-link parsing, stale permission cache during reseeding, a native-JSON assertion that failed on MySQL, opportunity query forwarding, mobile account navigation and shared bookmark state handling. The Turbopack CSS worker failed repeatedly; the supported webpack build passes. Local serving required explicit IPv4 loopback binding. A copied isolated MySQL data directory was used after an overnight Windows write-access failure; original data directories remain preserved.

No production data was used in the database rehearsal. Production import/extraction/media reconciliation, true backup/restore, target PHP/Node/cron/resource limits, live SMTP/YouTube/Turnstile, production DNS/TLS/CORS/cookies, hosted CI, observed queue/scheduler cycles, cPanel deployment and coordinated rollback remain unverified. The local production release guard correctly reports missing production prerequisites. These are required release gates, not implied passes.

The 16 October continuation adds persisted public configuration, shared session/navigation, real workspace search, channel controls/history and console contrast fixes. See `13-continuation-acceptance.md` for the browser evidence and fixture cleanup.

The content-filter and safe authentication-return continuation is documented in 14-console-content-navigation.md. It adds pagination-before-filter regression coverage, editor ownership checks, URL-preserved filters and MFA destination acceptance.



Editor feedback, edition categories/credits, video formats and cookie consent are covered in 15-editorial-feedback.md.


The production audit remediation is documented in 17-audit-remediation.md. Seven additional real HTTP release checks cover signed SSR traffic, 301 redirects, legal edition rendering/destinations, sitemap and browser-key exclusion. Build/startup identity and deployment receipt preflight regressions pass; approved legal content and actual hosting/provider/recovery acceptance remain open.



