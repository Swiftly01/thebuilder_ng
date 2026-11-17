# YouTube integration

The server uses official channels.list, playlistItems.list and videos.list APIs with a private key. Only the explicitly configured official channel is accepted; there is no scraping or browser API key.

A routine run reads at most three 50-item upload pages, enriches batches of 50 IDs, and reconciles 100 stored IDs through a persisted cursor. This bounds work as the library grows. Historical backfill beyond the recent upload window needs a reviewed operational run; unlimited archive import is not claimed.

Connection/response timeouts, a run budget, bounded transient retries, per-channel database cache locks and queued retry/backoff prevent unbounded work. Quota/invalid-key failures produce safe error codes. Provider URLs/keys are not returned in audit/history errors.

Provider and editorial title/description are separate. New videos stay imported unless automatic publication is explicitly enabled. Public reads require published, available and embeddable. Deleted/private/non-embeddable records are hidden on reconciliation. Editors review status/title/description/featured metadata in `/console/videos`; public previews use thumbnails and privacy-enhanced YouTube iframes on demand.

Dispatch runs every 15 minutes; bounded database workers run through the five-minute scheduler. This is not a one-minute upload SLA. Actual cPanel cron precision, quota, channel ownership and live upload-to-preview acceptance remain pending the user's channel/hosting configuration.

Tests explicitly fake provider HTTP responses for discovery/enrichment/replay, editorial override preservation, automatic publication, unavailable reconciliation, foreign-channel exclusion and quota errors. They validate code and failure behavior, not a live YouTube upload.

The 16 October frontend acceptance connects public settings, shared account navigation, the full permission-filtered console menu and real workspace search. YouTube controls now read existing channels, separate save/sync, expose import/publication/edition settings and display paginated synchronization history. See `13-continuation-acceptance.md`.
