<?php

declare(strict_types=1);

return ['turnstile' => ['secret' => env('TURNSTILE_SECRET_KEY'), 'hostnames' => array_filter(explode(',', env('TURNSTILE_HOSTNAMES', '')))]];
