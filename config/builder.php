<?php

declare(strict_types=1);

return ['site_domain' => env('BUILDER_SITE_DOMAIN', 'thebuilderng.com'), 'default_edition' => env('BUILDER_DEFAULT_EDITION', 'Nigeria'), 'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'), 'youtube_key' => env('YOUTUBE_API_KEY'), 'youtube_max_pages' => (int) env('YOUTUBE_MAX_PAGES', 3), 'events_enabled' => (bool) env('EDITORIAL_EVENTS_ENABLED', false), 'mfa_idle_minutes' => (int) env('MFA_IDLE_MINUTES', 30), 'ssr_secret' => env('BUILDER_SSR_SECRET'), 'ssr_requests_per_minute' => (int) env('BUILDER_SSR_REQUESTS_PER_MINUTE', 6000)];
