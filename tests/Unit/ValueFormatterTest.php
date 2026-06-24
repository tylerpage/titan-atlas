<?php

namespace Tests\Unit;

use App\Support\ValueFormatter;
use Tests\TestCase;

class ValueFormatterTest extends TestCase
{
    public function test_currency_always_uses_two_decimals(): void
    {
        $this->assertSame('$1,234.56', ValueFormatter::currency(1234.56));
        $this->assertSame('$12,345.00', ValueFormatter::currency(12345));
        $this->assertSame('$0.00', ValueFormatter::currency(0));
    }

    public function test_format_currency_matches_currency_helper(): void
    {
        $this->assertSame('$99.50', ValueFormatter::format(99.5, 'currency'));
    }
}
