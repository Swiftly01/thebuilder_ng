# Authentication and RBAC

Confirmed frontend domain: thebuilderng.com. Proposed API: api.thebuilderng.com; DNS/TLS/hosting are unverified. Sanctum's first-party cookies require the shared top-level domain and explicit stateful/CORS origins. Unrelated top-level origins need a fresh topology decision.

Browsers GET `/sanctum/csrf-cookie` with credentials, then send decoded XSRF-TOKEN as X-XSRF-TOKEN. The session is HttpOnly; production uses Secure/SameSite=Lax and the shared parent domain. No bearer/local-storage token exists. The Next proxy checks `/me` before private navigation; Laravel authorizes every operation.

Registration ignores client role fields. Verification links expire after 60 minutes and are signed/single-use. Verification/reset notifications queue after commit. Reset requests do not reveal account existence; reset revokes sessions. Password changes require the current password and revoke other sessions. Suspension/deactivation revokes sessions. Password-confirmed deletion deactivates immediately; daily purge removes reader data after 30 days and anonymizes retained editorial attribution.

| Role | Access |
|---|---|
| registered_user | Own profile, interests, bookmarks, history, submissions |
| content_editor | Content read/create/edit and media; no publishing, other-author edits or published-own-story bypass |
| editor_in_chief | Publishing, profiles, homepage, intake, media, audit, analytics, YouTube; excludes users/settings/ads/taxonomy administration |
| admin | All seeded launch permissions |

Console routes require MFA and permissions. MFA secrets are encrypted, TOTP timesteps cannot replay, recovery codes are hashed/single-use, and the idle challenge window is 30 minutes. Self-demotion/suspension and removal of the last active administrator are rejected. No administrator/password is seeded. Provision an existing active verified account with `builder:grant-admin EMAIL` and explicit operator confirmation; MFA is still required.

Login locks after five failures for 15 minutes, with auth/MFA/public/intake rate limits. Production public intake verifies Turnstile server-side against configured hostnames. Unexpected 5xx details are hidden; errors include request IDs. Images are type/size/pixel validated and re-encoded. Audit context redacts sensitive keys. Settings never return server credentials.

Real HTTP checks verified CSRF rejection, HttpOnly sessions, verification/MFA/role gates and logout. Production cross-subdomain browser behavior and SMTP delivery remain staging gates.
