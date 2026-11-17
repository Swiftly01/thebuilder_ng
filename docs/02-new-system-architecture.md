# Clean architecture

The candidate came from the official Laravel 12 skeleton 12.12.2 with a fresh lock. Locked framework: 12.70.0. PHP minimum: 8.2; local execution: 8.5.0. CI is configured for PHP 8.2/8.4 but has not been run on a hosted runner.

`app/Domain` contains Identity (TOTP/MFA), Editorial (sanitization/workflow/revisions), Discovery (published queries), Nominations (references), Videos (provider sync) and Administration (audit/import/contract). Versioned controllers, FormRequests, Resources, explicit fillable fields and relational Eloquent models form the HTTP boundary. There is no generic JSON resource store or legacy controller bridge.

Sanctum uses first-party HttpOnly cookie sessions. No bearer/browser-storage token is issued. Sessions, queues, cache and locks use MySQL; Redis is not a production dependency. Spatie Permission defines fixed launch roles. Console routes require active/verified accounts, MFA and permissions; article policies enforce object ownership and publication authority.

All domain endpoints use `/api/v1`. `/up` and `/sanctum/csrf-cookie` are framework infrastructure. No legacy domain aliases are installed. The existing Next.js Pages Router 16.4.0 / React 19 frontend consumes this contract through SSR loaders, browser actions and the root private-route proxy.

HTMLPurifier sanitizes editorial HTML. Media accepts JPEG/PNG/WebP and re-encodes WebP. Domain IDs are ULIDs; RBAC IDs remain package-native integers. Audit events redact secret/email/phone/token keys recursively and store an HMAC of the IP.

The old backend remains preserved under the cutover gates. Release artifacts must be built from the clean candidate only.
