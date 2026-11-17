<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\Totp;
use Tests\TestCase;

class TotpTest extends TestCase
{
    public function test_rfc_6238_six_digit_vector(): void
    {
        $this->assertSame('287082', app(Totp::class)->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));
        $this->assertNull(app(Totp::class)->matchingTimestep('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'bad', 59));
    }
}
