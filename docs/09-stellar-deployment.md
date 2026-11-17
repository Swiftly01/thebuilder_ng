# Stellar deployment runbook

No deployment has occurred. thebuilderng.com is confirmed; api.thebuilderng.com is proposed and requires DNS/TLS/account verification.

Inspect the actual cPanel account for web/CLI PHP and extensions (PDO MySQL, GD/WebP, curl, mbstring, DOM, OpenSSL, fileinfo), MySQL/MariaDB version/limits, Composer/SSH, document roots/symlinks, HTTPS, SMTP ports, Node/Passenger, cron cadence, resource limits and storage quota. General provider documentation does not establish actual account configuration.

Namecheap's published minimum cron cadence is five minutes: https://www.namecheap.com/support/knowledgebase/article.aspx/1212/22/do-you-allow-cron-jobs/ . General software information: https://www.namecheap.com/support/knowledgebase/article.aspx/129/22/what-version-of-the-software-is-used-on-your-servers/ . Actual hosting checks remain pending.

## Release procedure

1. Obtain explicit deployment authorization and record verified hosting/topology facts.
2. Preserve and restore-test the real database/uploads, review eligible extraction/import/media mappings and reconcile counts, relations, URLs, password algorithms and consent. Freeze writes for final cutover.
3. Run API/frontend quality, contract and integration checks. Build a new artifact with `tools/package-release.ps1`. It uses a positive source list, installs locked --no-dev dependencies separately and records hashes; private environments, databases, archives, preview credentials and old backend files are excluded.
4. Keep releases under `/home/ACCOUNT/builder/releases/RELEASE`, private shared environment/media/logs under `/home/ACCOUNT/builder/shared`, and the API document root at accepted `current/public` only. Never expose the application root/.env or use 777. Preserve APP_KEY across releases.
5. Configure `deploy/.env.production.example` for the verified topology. Sessions/cache/queues use MySQL; CORS is explicit, cookies Secure/HttpOnly on the shared parent domain. Supply SMTP, Turnstile and official YouTube configuration privately. Run `composer check-platform-reqs --no-dev` on the target.
6. Review `deploy/prepare-release.sh` for the account before execution. Supply a private fresh receipt under `shared/backups` using `deploy/backup-receipt.example.json`: exact target DB/release identity, release manifest hash, actual DB/upload archive hashes and matching operator-recorded restore verification within 24 hours. Do not falsely mark a restore verified. The script enforces canonical paths and an empty release bootstrap cache, then runs configuration/backup preflight before migrations/seeds; invalid settings fail before DB access. It subsequently uses persistent media/logs, migrates the clean target, seeds fixed permissions/taxonomy, links media, builds caches and runs the post-migration guard. It does not activate a document root. Never run reset/fresh against production. A rejected one-shot preparation requires operator review; do not remove stored data or rerun blindly.
7. Verify /up, /api/v1/home, storage, cross-origin cookies, private gates, actual verification/reset/intake emails, publication, media, provider import and 404/503 behavior. `builder:release-check` checks configuration only.
8. Install the reviewed five-minute cron using the actual CLI PHP path (`deploy/cron.example`). Observe at least two scheduler/queue cycles and a real scheduled article/video. Publish-due is every five minutes, YouTube dispatch every 15. Worker max-time=60, timeout=180, retry_after=240 and overlap locks require actual hosting verification. No Redis or persistent queue daemon is needed.
9. Register/verify the initial administrator, run `builder:grant-admin EMAIL` with explicit operator confirmation, enroll MFA and store recovery codes privately. No administrator/password is seeded.
10. Activate coordinated frontend/API releases only after acceptance and authorization. Record hashes, backup receipts, smoke results, ownership, cron evidence and rollback paths.

The frontend requires a Node-capable SSR host. Verify supported cPanel Node/Passenger or use an appropriate Node host mapped to thebuilderng.com. Do not force a static export merely to fit unverified hosting assumptions.

Use Node 22/24 LTS and `BUILDER_BUILD_TARGET=release` with verified production origins and real public Turnstile configuration. Set the same private random `BUILDER_SSR_SECRET` in the frontend server/API (never NEXT_PUBLIC), synchronize clocks and size the bounded service budget from actual measurements. The NG cookie setup does not establish authentication on a separate AU domain; finalize topology first.

Supply approved edition policies/contacts in `frontend/contracts/legal-content.json`; pending entries block release. Run frontend release preflight and `npm run build`. Its public configuration, legal hash and build ID are sealed; startup rejects mismatches. Loopback preview builds cannot be promoted by changing startup variables. Keep the dated tooling risk review current: new/changed/overdue findings block CI. The unpatched braces advisory needs a named release-owner decision.

Monitor private retained logs/request IDs, failed_jobs, provider sync history and scheduler logs; alert on failures or missed cycles. Back up database/media together and restore-test off production. Before rollback freeze writes, preserve the current database/uploads, and reconcile post-cutover writes before restoring matching releases/data. Real production rollback remains an outstanding gate.

