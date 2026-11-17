# Production readiness QA audit — 16 October 2026

**Decision: NO-GO for public deployment.** The application is a working local release candidate, with passing regression checks, but this audit found release defects and outstanding operational acceptance gates. Passing a build does not establish production readiness. No numerical readiness score or claim of exhaustive security certification is made.

Scope: the current `frontend` source and clean `.replatform/laravel12-backend` candidate, API artifact `api-candidate-20261016-editorial`, and existing deployment/recovery documentation. This pass reviewed authentication/authorization, public rendering, editorial redirects, uploads, traffic limits, environment configuration, legal navigation, dependency advisories, CI and deployment operations. It repeated the automated suites and real HTTP journeys, and added targeted fault probes. It did not deploy, change application implementation, replace the old backend, contact external mail recipients, or use production data. Audit-only probes and receipts live under `.replatform/runtime` and are excluded from release artifacts.

## Findings requiring action

P1 means a high-priority release blocker. P2 means a material defect or risk requiring correction or explicit, documented acceptance before release. The advisory severity reported by a dependency scanner is distinguished from its exposure in this application.

| ID | Priority | Finding | Evidence | Closure criterion |
| --- | --- | --- | --- | --- |
| F01 | P1 | Public rate limiting combines all anonymous SSR visitors into the frontend server's API IP budget. | `app/Providers/AppServiceProvider.php:23`, `routes/api.php:23`, `frontend/src/lib/public-ssr.ts:13`, `frontend/src/lib/http.ts:149`; isolated probe confirms request 121 to the shared public bucket is HTTP 429. | Introduce bounded public caching and an authenticated, constrained SSR request strategy or another reviewed limiter topology. Keep browser abuse protection. Test aggregate anonymous traffic from a single frontend egress IP without accepting spoofed client-IP headers. |
| F02 | P1 | Existing article URLs fail after a published slug changes. | Laravel emits HTTP 301 with `{data:{redirect:...}}`; the HTTP client throws that envelope, while `public-ssr.ts:31` reads `error.body.redirect`. Real HTTP reproduction: API 301, frontend **503**, no Location header. | Align the redirect contract and handle it in both SSR and client navigation; test old URL → current published article, repeated renames, unavailable targets and unsafe destinations. |
| F03 | P1 | Deployment preparation can mutate the configured database before production configuration is rejected. | `deploy/prepare-release.sh:26–31` runs `migrate --force` and `db:seed --force` before `builder:release-check`. `BACKUP_RECEIPT` is checked only for file existence at line 10. | Run a configuration/target preflight before database writes, separately from post-migration checks. Bind a verified backup/restore receipt to the intended database, uploads and release; reject stale/mismatched receipts. Demonstrate failure-before-write and rollback on staging. |
| F04 | P2 | A failed media write is acknowledged as a successful upload. | `MediaController.php:46` ignores `Storage::put()` returning false; the public disk has `throw=false`. Fault injection returned false, but the API returned **201** and created one media record for a missing asset. | Reject unsuccessful writes, preserve database/filesystem consistency, and test disk-full/permission/write failure plus failed cleanup/deletion behavior. |
| F05 | P2 | Production-mode frontend configuration accepts absent release origins and silently substitutes localhost. | Isolated evaluation of the actual `next.config.ts` with only `NODE_ENV=production` succeeds, exposing `http://localhost:3000` and `http://localhost:8000/api/v1`. The fresh local production-mode build is intentionally a preview build. | Add an explicit release preflight that validates approved HTTPS origins, API path/topology, cookie name and required public provider configuration. Preserve an explicit local-preview workflow. Build and smoke-test a new production-configured frontend artifact. |
| F06 | P2 | Footer policy/contact destinations do not reach the promised content. | Browser navigation to `/about#sponsored` reaches About without a sponsored policy; `standards`, `advertise`, `contact` and `sponsored` IDs are absent. `Footer.tsx:38–53`; `ExploreScreens.tsx` About section. Privacy/Terms still contain unapproved `thebuilder.africa` contacts; Privacy promises account newsletter preferences that are not implemented there. | Install the publisher-approved Terms, Privacy and Sponsored Content documents; route/footer links to real sections/pages; verify mailboxes, business addresses and claims for both editions. Do not invent legal text or publisher contacts. |
| F07 | P2 | The older frontend CI workflow still selects Node 20, which is end of life. | `frontend/.github/workflows/ci.yml:22` uses Node 20; the newer `quality.yml:17` uses Node 22. Both workflows remain. Local audit used Node 24.18.1. | Consolidate/update workflows to a supported LTS runtime matching the intended SSR host, then obtain a successful hosted run for the exact release source. |
| F08 | P2 | Development tooling retains a known high-severity dependency advisory. | Fresh full npm audit: seven affected dependency entries, all related to `braces <=3.0.3`, GHSA-vfj7-8cjw-p6xm. Production-only npm audit: zero. Composer: zero advisories and zero abandoned packages. | Record an owner, bounded exposure assessment and review date; prevent untrusted glob patterns in build tooling; adopt a compatible upstream fix or a verified migration when available. Keep the complete audit and do not report all dependencies as vulnerability-free. |

F01 detail: every homepage SSR request performs at least `/home` and `/settings/public` through uncached server fetches. In the current topology those requests share the frontend server's outbound IP. In a fresh 120-request window, approximately 60 home SSR requests can consume that shared allowance, before category/detail requests or other public calls are counted. This is an inference from the confirmed limiter and fetch paths, **not a measured production capacity benchmark**. Direct client fetches and trusted hosting/proxy configuration will also affect observed behavior. Simply increasing the limit or trusting arbitrary `X-Forwarded-For` values does not close the finding.

F02 reproduction receipt: `.replatform/runtime/qa-audit-redirect.json`, recorded at 17:40 UTC. A uniquely marked preview redirect pointed to an existing published preview article. The frontend rendered “Content is temporarily unavailable”; screenshot `qa-audit-redirect-20261016.jpg`. The exact temporary redirect was removed after reproduction. The two isolated PHPUnit fault probes deliberately assert the currently defective behavior; their green result confirms reproduction, not release acceptance.

F03 is confirmed by source/control-flow inspection, not execution of the deployment script. No deployment or database migration was attempted on a real host. Manual review in the runbook remains necessary, but does not make the script's ordering safe by itself.

## Fresh verification performed in this audit

| Check | Result | Evidence |
| --- | --- | --- |
| Laravel regression suite, SQLite in memory | **49 tests / 371 assertions pass** | Candidate `qa-audit-sqlite.txt`, `qa-audit-sqlite.xml` |
| Laravel regression suite, isolated MySQL 8.4.11 | **49 tests / 371 assertions pass** | Candidate `qa-audit-mysql.txt`, `qa-audit-mysql.xml`; named disposable test database only |
| Targeted shared-limit and failed-storage probes | **Both defects reproduced**; 2 probes / 127 assertions | `.replatform/runtime/ProductionAuditProbeTest.php`, candidate `qa-audit-defect-probes.txt` / XML |
| Larastan level 6 | Zero errors, no baseline | Candidate `qa-audit-phpstan.json` |
| Pint | Pass | Candidate `qa-audit-pint.txt` |
| Frontend production-mode local preview build | Pass; 40 static pages generated plus dynamic routes/proxy | Root `build-qa-audit.log`; uses local preview origins, not deployable production configuration |
| TypeScript | Pass | `npm run type-check` |
| ESLint | Zero errors; **84 warnings** across 168 checked files | `frontend/lint-qa-audit.json`; warnings remain review debt |
| Prettier | Pass | Root `qa-audit-format.txt` |
| OpenAPI contract | 84 paths / 105 operations valid; frontend mappings checked | `npm run check:contract` |
| Authentication returns, settings, editorial policy, cookie analytics gates | Pass | `check:auth`, `check:settings`, `check:editorial` |
| Theme and contrast checks | 93 token checks; 12 first-paint scenarios; 40 public route files / 129 shared sources; 33 text contrast checks pass | Root `qa-audit-theme.txt`; not a full WCAG certification |
| Real HTTP acceptance | **14 journeys pass** | Root `qa-audit-http.txt`, runtime `http-integration-results.json`; local MySQL preview and log-only mail |
| Browser spot checks on fresh build | Mobile Builder submenu, focus wrap and Escape focus return pass; no horizontal document overflow at requested 390px viewport; no captured browser errors | Runtime `qa-audit-browser.json`; IAB Chromium only |
| Legal link navigation | **Fails** as F06 | Actual `/about#sponsored` navigation and DOM target checks |
| Production configuration guard | Exits nonzero on the preview environment, as expected | Candidate `qa-audit-release-check.txt`; genuine production configuration has not been supplied |
| API production artifact | **6,123 file hashes match**, 76 production packages, private/runtime/development/legacy content excluded | Runtime `package-verification.json`, refreshed 17:36 UTC |
| Source checkpoint identity | SHA-256 unchanged | `source-checkpoint-20261016-editorial.zip`: `BA97BF689327DC8682E22C2A665905C75906CBFAD32313547323A7A31584D2FA` |
| Fresh dependency advisory queries | Runtime npm zero; full tooling seven high affected entries; Composer zero/none abandoned | Frontend `audit-production-readiness-{runtime,all}.json`; candidate `audit-production-readiness.json` |

The 14 HTTP journeys cover CSRF rejection, cookie registration and client-role rejection, unverified account gates, queued signed verification mail, account/onboarding persistence, bookmarks/history, MFA enforcement, real multipart WebP serving, editorial authorization/sanitization/publishing and SSR visibility, console filtering, nomination idempotency/review, role-change audit records, settings rendered in SSR, private redirects/404/CSP hash and logout invalidation. They use fixtures and local log mail; they do not prove live delivery or provider behavior.

Earlier evidence reviewed, not rerun as part of this audit: all 12 migrations rollback/fresh successfully on isolated MySQL; a 46-table dump restores with matching column definitions/counts, joined fixture relationships and foreign-key rejection; 649 preserved old-backend files match their archive. Those receipts were produced earlier on 16 October. They do **not** prove production-data restoration, production import reconciliation or rollback of real post-cutover writes. The prior API-outage/recovery test also remains prior-pass evidence, not a new result from this audit.

## External release gates still open

| Gate | Required acceptance evidence | Responsible party |
| --- | --- | --- |
| Hosting and runtime | Named Namecheap/staging connection; verified PHP web/CLI version/extensions/platform requirements, MySQL version/limits, Node SSR support, symlinks/document roots, cron cadence, filesystem quota and resource limits. Run on Linux/target PHP and the actual intended Node LTS. | Hosting operator + engineering |
| Domains and browser security | Approved API origin, DNS/TLS/redirects/HSTS/proxy configuration, Secure/HttpOnly/SameSite cookies, credentialed CORS and CSRF/MFA behavior from actual public origins. `api.thebuilderng.com` is still a proposal. | Site owner + engineering |
| Australia topology | Clarify whether Australia is an edition on the NG host, an independently hosted domain, or another architecture. Current release guard and shared-cookie setup approve NG domains only. A browser on `thebuilderau.com` cannot read the XSRF cookie scoped to `.thebuilderng.com`; simply adding CORS does not establish this cookie-based authentication topology. | Site owner + engineering |
| Real data and recovery | Inventory the real database/uploads; private matched backups; restore test; reviewed extraction/import/media mapping; counts/relationships/password/consent/URL reconciliation; write freeze/cutover and post-cutover rollback procedure. Agree recovery point/time targets and an operator. | Data owner + engineering |
| Live integrations | Official YouTube channel/server key and actual sync/reconciliation; SMTP/sender plus delivered verification/reset/intake messages; Turnstile production keys/hostnames and expired/used-token/error/retry behavior. | Site owner + engineering |
| Legal and publisher content | Approved Terms, Privacy and Sponsored Content Policy from Kabir; verified public contact/office details; publisher review of the cookie inventory/choices and data-retention claims. The consent component is implemented, but that is not legal approval. | Publisher |
| Scheduler and observability | Observe at least two real cron/worker cycles and scheduled publication; confirm job timeout/retry budget and hosting CPU/process limits, failed-job handling, missed-cycle/error alert delivery, backup ownership and log rotation. Current request IDs/logs/history are useful but no live alert path has been demonstrated. | Hosting operator + engineering |
| Release quality and capacity | Green hosted CI on exact source; production-configured build; staging regression/fault tests after fixes; representative dataset and concurrent-traffic latency/error/resource measurements against an agreed budget; browsers including Safari/Firefox and real mobile, keyboard/screen-reader/zoom/form-error accessibility acceptance. | Engineering + product |

These are unverified requirements, not asserted hosting failures. The application can continue through local remediation and then authorized staging acceptance. Public activation requires closure of release blockers and the applicable gates. The old backend must remain preserved until the controlled cutover criteria are satisfied.

## Recommended execution order

1. Fix F01–F05 with regression tests that fail against the present defects. Add safe deployment preflight/failure tests without touching production.
2. Repair real footer destinations and install approved legal/publisher content (F06). Update/consolidate CI (F07) and record a controlled tooling-advisory decision (F08).
3. Obtain the outstanding private connection/configuration locations, finalize the NG/AU topology, run the hosted matrix and build new immutable release artifacts using verified production settings.
4. Complete real backup/import/recovery and authorized staging acceptance, including provider delivery, cross-origin authentication, scheduled jobs, monitoring and representative traffic/accessibility testing.
5. Re-audit the exact new artifacts. Record owners, release/backup hashes, evidence and rollback instructions, then obtain explicit activation authorization.

No application fix or replacement release artifact was produced by this audit. The previous API/source packages remain unchanged and retain these findings. The existing local app is suitable for continued remediation and controlled staging preparation, not an unconditional production launch.

## Primary reference checks

- [Laravel 12 Sanctum SPA authentication](https://laravel.com/docs/12.x/sanctum): first-party session authentication requires the SPA/API domain and cookie/CORS topology to be configured together. The AU deployment implication above is an inference from that documented requirement and this repository's cookie/CSRF implementation.
- [Node.js release status](https://nodejs.org/en/about/previous-releases) and [official release schedule](https://github.com/nodejs/Release/blob/main/schedule.json): Node 20 is EOL; use a supported LTS release for deployment and CI.
- [GitHub reviewed braces advisory GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm): the advisory lists versions through 3.0.3 as affected and no patched version at audit time. Seven npm entries are dependency paths around one underlying advisory, not seven independent application exploits.

References and package registries were checked on 16 October 2026. This is an engineering readiness audit, not a legal opinion, external penetration test, accessibility certification or guarantee against future vulnerabilities.
