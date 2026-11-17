<?php

declare(strict_types=1);

return ['paths' => ['api/*', 'sanctum/csrf-cookie'], 'allowed_methods' => ['*'], 'allowed_origins' => array_filter(explode(',', env('FRONTEND_ORIGINS', 'http://localhost:3000'))), 'allowed_origins_patterns' => [], 'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Request-ID', 'If-Match', 'Idempotency-Key'], 'exposed_headers' => ['X-Request-ID', 'ETag', 'Retry-After'], 'max_age' => 600, 'supports_credentials' => true];
