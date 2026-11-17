# Console filtering and authentication continuation

16 October 2026, Africa/Lagos. This continuation closes additional integration gaps in the local candidate. No deployment or backend-directory cutover occurred.

## Changes

Content title/author search, workflow status and edition now run in Laravel before pagination. Query conditions are grouped so author matches cannot bypass the status, edition or ownership scope. Editors without publishing permission only list their own stories, matching the existing detail policy. Creation time and ID give pagination a stable order. Edition filters match the stored edition exactly, including the explicit `both` value.

The Content screen submits these filters through its URL, resets to page one when filters change, restores selected values after reload, and retains the filters in pagination links. It shows the API's matching total and keeps search, status, edition and clear controls available when nothing matches. Status tabs no longer label current-page counts as collection totals.

Sign-in and email-verification continuation share the same destination logic. MFA challenge and setup carry the requested path through to completion. Return parameters must be same-origin site paths: absolute/protocol-relative URLs, backslashes, encoded backslashes/slashes that introduce an authority, control characters, malformed encodings and non-string values are rejected. Sign-in/register cross-links retain valid destinations.

The taxonomy form now exposes the existing category parent/description contract, validates lengths and slugs, displays server validation errors and supports cancelling edits. Switching categories/tags clears the selected record; the active tab is URL-backed so saving or reloading retains it. Both console layouts explicitly use light native form controls within their fixed light palette; this prevents dark browser defaults from obscuring text when the public theme is dark.

## Verification

- SQLite and isolated MySQL: **44 tests / 321 assertions each**. New cases put the target behind 21 newer records, combine author/title/status/edition filters, check disjoint pagination, search for `0`, reject invalid filters, and enforce editor ownership and reader denial.
- Larastan level 6: zero errors without a baseline. Pint passes.
- OpenAPI: 84 paths / 105 operations, including the new edition query declaration and search/ownership behavior. The frontend contract check verifies the supported content-filter parameters.
- Frontend: optimized webpack build, TypeScript, theme/settings/authentication checks and Prettier pass. ESLint remains zero errors / 89 existing warnings.
- Real HTTP: **14 grouped journeys pass**, including combined content filters. Production dependency audit results remain unchanged because no dependency or lockfile changed.
- Browser: normal administrator login and TOTP returned to `/console/content` with the original search and `page=2`; three of 23 disposable matching drafts/review records appeared on page two. Edition/status changes reset to page one and found the older target. A full reload retained the selections. A zero-result combination retained controls, and clearing recovered the full list. Submitting the content search found the older target.
- Taxonomy browser acceptance: parent/description persisted after reload; a cycle produced the server validation message; cancelling reset the form; switching from a category edit to tags created a new tag and retained the Tags tab after saving and reloading. No inspected browser errors were logged.
- Dark public theme: the console search uses `rgb(15,23,42)` text on a white parent, a transparent input background and native `color-scheme: light`. The 390px viewport has no document overflow.

Browser receipt: `.replatform/runtime/browser-acceptance-20261016-navigation.json`. Screenshot: `console-content-filters-20261016.jpg`. Build output: `build-replatform-navigation.log`. The 23 content fixtures and seven marked HTTP test stories were soft-deleted; taxonomy fixtures were removed. Temporary UI accounts were suspended, their passwords randomized and sessions revoked after acceptance.

Another local project occupied port 8000 during final browser checks. Only The Builder's preview was moved: API `http://localhost:8012`, frontend `http://localhost:3000`. Private local environment files contain that override; deployment templates and production origins are unchanged. The complete 14-journey HTTP suite passed again on the isolated port. To repeat it against that preview, set `BUILDER_LOCAL_API_URL=http://localhost:8012`; the script only accepts loopback HTTP origins and still verifies the guarded preview database/log-mail configuration.

## Artifacts and remaining gates

This revision is packaged separately as `.replatform/releases/api-candidate-20261016-navigation` and `.replatform/releases/source-checkpoint-20261016-navigation.zip`; earlier verified artifacts remain intact. Private runtime data and credentials are excluded.

The API artifact contains the unchanged, verified API implementation for this revision (6,119 hashed files / 76 production packages). The source checkpoint also includes the subsequent frontend taxonomy/native-control acceptance and this completed report.

The local preview build still targets localhost. Production requires a rebuild with verified origins. Namecheap staging/PHP/Node/MySQL/cron access, real data/upload preservation and import reconciliation, official YouTube credentials/channel, SMTP, Turnstile, production DNS/TLS/cookies/CORS, hosted staging checks and authorized activation remain pending. The user requested a reminder to provide configuration locations after local readiness work.
