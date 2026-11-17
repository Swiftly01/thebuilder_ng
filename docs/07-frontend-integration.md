# Frontend integration

Updated 16 October 2026, Africa/Lagos. Existing layouts, type, tokens and themes are preserved. The original UI/UX HTML remains the design reference. Baseline source/route inventories are in the accompanying JSON files.

The HTML manifest supplied the shared Skeleton and Button Dots source: 1.6-second opacity shimmer, 62% final text rows, three 6px dots with 5px gaps and 150ms stagger. Reduced-motion is supported. The earlier full-screen B-logo interpretation was replaced by these actual HTML loading components.

| Surface | Integration | Acceptance boundary |
|---|---|---|
| Home and story/category/detail | SSR API data, real order/visibility/pins, sanitized body, metadata, bookmarks, 404/503 | Actual HTTP publication reaches the built frontend; category empty/one-record states are handled |
| Builders/startups/diaspora/opportunities/videos/search | Real collections/details/filters/pagination, consent/founder privacy, real suggestion palette and video iframe | API feature tests plus public browser inspection; live YouTube still pending |
| Auth and account | Sanctum/CSRF, verify/reset/MFA, onboarding, profile/interests/bookmarks/history/password/sessions/deletion | Real HTTP reader journey; no browser-storage bearer token |
| CMS/editor/profile/homepage/intake | Real IDs, If-Match, controlled transitions, versions, consent/founders, pins and reference/status workflows | Feature and HTTP editorial/nomination/admin journeys |
| Admin/media/taxonomy/settings/analytics/ads/videos | Relational persistence, real counts, uploads, permissions and video review | API acceptance; actual editorial configuration and provider credentials pending |
| About/legal/system | Existing static content; cookie choices are local preferences | Editorial/legal copy still requires owner signoff |

Public loaders cancel stale requests and return real 404 or retryable 503. Edition cookies seed SSR/client state, including blocked-storage handling. Production builds refuse design-preview mode; unused mock repositories were removed. The private proxy and SessionProvider complement server authorization. The console state gallery is development-only. Robots and sitemap consume configuration/API data.

The release build uses supported --webpack because Turbopack repeatedly crashed its CSS worker inside and outside the sandbox. This is a recorded tooling limitation, not a passing Turbopack build.

Use .env.example locally and .env.production.example for the proposed production topology. Turnstile is intentionally unset; production intake shows an honest unavailable state until configured. No secret belongs in NEXT_PUBLIC variables. Final checks/results are in 10-testing-results.md.
