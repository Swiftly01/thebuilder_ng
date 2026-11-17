<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SafeNavigationUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/[\\\\\x00-\x20\x7f]/', $value)) {
            $fail('Use an absolute HTTP(S) URL or a site path beginning with one slash.');

            return;
        }
        $internal = str_starts_with($value, '/') && ! str_starts_with($value, '//');
        $external = filter_var($value, FILTER_VALIDATE_URL) && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true) && parse_url($value, PHP_URL_USER) === null && parse_url($value, PHP_URL_PASS) === null;
        if (! $internal && ! $external) {
            $fail('Use an absolute HTTP(S) URL or a site path beginning with one slash.');
        }
    }
}
