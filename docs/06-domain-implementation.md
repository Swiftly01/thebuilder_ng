# Domain implementation

The clean candidate provides relational APIs and connected UI operations. Feature completion is assessed from acceptance evidence, not compilation alone.

| Domain | Implemented | Verification boundary |
|---|---|---|
| Editorial | Sanitized drafts/body, taxonomy/media/profile relations, SEO, review/changes/rejection/approval, scheduling/publish/unpublish/archive, versions/restore, redirects | Feature tests and actual HTTP publication through the built SSR frontend; hosting cadence pending |
| Discovery/profiles | Consent, classifications, diaspora/country/region, startups/founders, related stories, featured metadata, search, expiring opportunities | Privacy/publication/relationship tests; no fabricated records |
| Reader | Registration/verification/onboarding, own profile/privacy/interests, bookmarks/history, password/sessions and deletion grace | Feature tests and HTTP reader journey |
| Intake | Builder/startup nominations, references/idempotency, review stages, linked drafts, story acceptance/decline, queued acknowledgment/outcomes | Feature tests and HTTP nomination/review |
| Newsletter | Consent, queued double opt-in, expiring hashed confirmation and unsubscribe | Interface/feature tests; SMTP and bulk editorial mailing unverified |
| Administration | Fixed RBAC, user status/roles, taxonomy hierarchy, settings, homepage order/visibility/pins, audit, consented analytics, campaign persistence | Permission/state/validation tests; actual editorial configuration/traffic pending |
| Video | Official-channel configuration, bounded sync, reviewed or explicit automatic publication, metadata overrides, reconciliation, featured status and public preview | Explicit HTTP provider fakes; live channel/key pending |

Notifications use database queues and after-commit delivery. The first real HTTP mail test exposed an empty local From address; correcting the local example sender enabled the complete verification journey. Production sender fields remain unset until SMTP details are supplied.

Comments, following, personalized recommendations, social login and podcasts remain outside launch scope. Editorial event discovery is disabled/coming-soon and contains no registration, tickets, sponsors, volunteers or payment workflow.

The 16 October frontend acceptance connects public settings, shared account navigation, the full permission-filtered console menu and real workspace search. YouTube controls now read existing channels, separate save/sync, expose import/publication/edition settings and display paginated synchronization history. See `13-continuation-acceptance.md`.
