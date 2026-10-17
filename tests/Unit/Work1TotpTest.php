<?php

namespace Tests\Unit;

use App\Services\Work1\Work1Totp;
use PHPUnit\Framework\TestCase;

class Work1TotpTest extends TestCase
{
    public function test_rfc_vector_at_unix_time_59(): void
    {
        $this->assertSame(
            '287082',
            Work1Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59),
        );
    }
}
