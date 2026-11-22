<?php

namespace Tests\Unit;

use App\Support\Decimal;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_parsing_statement_amounts(): void
    {
        $cases = [
            ['1,234.56', '.', '1234.5600000000'],
            ['1.234,56', ',', '1234.5600000000'],
            ['(12.50)', '.', '-12.5000000000'],
            ['12.5-', '.', '-12.5000000000'],
            ['-0.000123', '.', '-0.0001230000'],
            ['$ 7.10', '.', '7.1000000000'],
            ['€1 234,5', ',', '1234.5000000000'],
            [0.1, '.', '0.1000000000'],
            [3, '.', '3'],
        ];
        foreach ($cases as [$input, $sep, $expected]) {
            $this->assertSame($expected, Decimal::parse($input, $sep), 'Input: '.var_export($input, true));
        }

        $this->assertNull(Decimal::parse('', '.'));
        $this->assertNull(Decimal::parse('Total', '.'));
        $this->assertNull(Decimal::parse('.', '.'));
    }

    public function test_rounding_and_percentages_are_exact(): void
    {
        $this->assertSame('0.000002', Decimal::round('0.0000015'));
        $this->assertSame('-0.000002', Decimal::round('-0.0000015'));
        $this->assertSame('0.700000', Decimal::percentOf('1', '70'));
        $this->assertSame('0.000006', Decimal::percentOf('0.000009', '62.5'));   // 0.000005625 → half up
        $this->assertSame('0.003333', Decimal::div('0.01', '3'));

        // 0.1 added ten times is exactly 1 (it is not with floats).
        $this->assertSame('1.000000', Decimal::sum(array_fill(0, 10, '0.1')));
    }
}
