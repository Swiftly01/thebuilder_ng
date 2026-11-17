# Editor-in-chief feedback implementation

16 October 2026, Africa/Lagos. Implemented against the forwarded website feedback. This is a locally verified candidate, not a deployed release.

| Feedback | Implemented behaviour |
|---|---|
| Reduce header items | Default desktop/mobile navigation is Home, Stories, The Builder, Videos and Opportunities. Startups, Industries and About remain in the footer. Explicitly configured custom menus remain editable in Settings. |
| The Builder dropdown | Directory, Nominate a Builder, Builder of the Month, Submit a Story and Diaspora. The Month link uses a real API feature filter, limited to published, consenting and currently featured profiles. |
| Builders Beyond Borders | Australia, Africa, UK, US and Canada only. Selecting a region fetches its published profiles rather than filtering an incomplete homepage page. Empty/error states are honest; the designer's existing skeleton is reused. |
| Remove Editor's Picks | Removed from homepage rendering, public homepage data and console section editing. Existing stored configuration is retained for preservation but cannot be re-enabled through the console API. |
| Newsletter name | The Builder Newsletter across active frontend copy and queued confirmation/welcome messages. Both subscription forms use the selected edition. |
| Footer attribution | Australia: TechPro Consulting Pty Ltd. Nigeria: TechPro Nigeria. Independent plain-text attribution fields are available in Settings; old Osun attribution falls back to the approved edition credit. About uses the same credit. |
| Australia stories | Business, Startups, Community, Entrepreneurs, Academia, Research, Policy, Interviews. |
| Nigeria stories | Business, Startups, Community, Entrepreneurs, Academia, Diaspora, Interviews. |
| Category integration | The same ordered categories drive header dropdowns, Stories filters, edition-filtered category API results and new editor selections. Seeds add missing categories without deleting historical taxonomy/content. Changing edition clears an unavailable category and resets story pagination. |
| Diaspora header | Inside The Builder dropdown, including the mobile disclosure. The hub links to the five focus locations. |
| Videos dropdown | Interviews, Podcast and Documentary plus All videos. A nullable indexed editorial `format` field, console control and validated API filter support the destinations. Existing videos stay unclassified until reviewed; YouTube synchronization preserves the editorial format. Podcast is a video category, not an enabled audio/RSS product. |
| Subscribe | Opens the actual newsletter subscription form, headed The Builder Newsletter. Existing double opt-in remains in place. |

## Cookies and the policy question

Yes, the platform uses essential Laravel session/CSRF cookies and an edition-preference cookie. Theme/edition preferences use browser storage. Optional first-party article analytics use a random session identifier and remain off until explicit opt-in.

Added a first-visit banner, equal-visibility Essential only/Allow analytics controls, a technical `/cookies` notice and persistent preference controls accessible from the footer. The versioned choice lasts up to 180 days, expires safely and can be withdrawn. Withdrawal stops future optional events and clears the analytics identifier; previously collected events are not silently represented as deleted. Blocked storage retains the explicit choice for the current visit. A legacy analytics flag alone no longer enables tracking.

The notice describes purpose, storage, default durations, the edition's publisher credit, configured Turnstile security checks and YouTube behaviour. Embedded video players load only after a reader chooses to play; preview images can contact YouTube before playback. No third-party advertising pixel was introduced.

This approach follows the NDPC's requirements for prominent understandable cookie information, identifying the responsible organisation and explaining withdrawal. Australian privacy guidance also stresses clear disclosure of tracking and personal-information handling. See [NDPC GAID 2025, Article 19](https://ndpc.gov.ng/wp-content/uploads/2025/07/NDP-ACT-GAID-2025-MARCH-20TH.pdf) and [OAIC guidance on tracking transparency](https://www.oaic.gov.au/news/blog/your-life%2C-pixelated-how-tracking-pixels-watch-your-every-click).

The technical notice is not a substitute for the publisher's final legal documents or legal approval. Kabir's Terms and Conditions, Privacy Policy and Sponsored Content Policy remain pending. Final controller/contact details, target-host cookie lifetimes and any additional providers must be reconciled with those approved documents before launch.

## Evidence and release gates

- API: **49 tests / 371 assertions on both SQLite and isolated MySQL 8.4.11**. Larastan level 6 has zero errors; Pint passes. New cases cover edition categories, disabled Picks, format filtering/validation/privacy, current Month features and independent attribution settings. Provider refresh preserves the editorial format.
- Migration/recovery: 12 migrations reset/reapplied on the isolated test schema; 46 tables dumped/restored; full column definitions, joined fixture relationships and foreign-key rejection pass. No production database was used.
- Frontend: TypeScript, optimized webpack build, Prettier, theme, settings, safe auth navigation, editorial policy parity, cookie/analytics gating and OpenAPI checks pass. ESLint has zero errors / 84 existing warnings. OpenAPI remains 84 paths / 105 operations with the new query/settings/format declarations.
- Real HTTP: all 14 grouped journeys pass against API port 8012 and the built frontend on port 3000.
- Browser: compact desktop/mobile navigation, Builder dropdown, both exact category menus and attribution credits, edition persistence, the Podcast destination, newsletter form, first-visit banner, consent opt-in/persistence/withdrawal, five regional tabs and absent Picks were checked. The 390px viewport has no document overflow. Screenshot: `editorial-feedback-20261016.jpg`; receipt: `.replatform/runtime/browser-acceptance-20261016-editorial.json`.

Latest artifacts: `.replatform/releases/api-candidate-20261016-editorial` and `.replatform/releases/source-checkpoint-20261016-editorial.zip`. Previous artifacts and the old backend remain preserved. The compiled frontend uses local preview origins and must be rebuilt for verified production origins.

Still required before deployment: approved legal documents; actual Namecheap/PHP/Node/MySQL/cron/staging configuration; real data/upload preservation and import reconciliation; production origins/DNS/TLS/CORS/cookies; official YouTube access, SMTP and Turnstile; hosted staging acceptance, rollback and authorized activation. Existing development-tooling audit findings remain documented; no dependency or lockfile changed in this feedback pass.
