<?php

declare(strict_types=1);

return ['issuer' => env('MFA_ISSUER', 'The Builder'), 'period_seconds' => 30, 'digits' => 6];
