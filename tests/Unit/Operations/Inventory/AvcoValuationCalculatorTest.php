<?php

namespace Tests\Unit\Operations\Inventory;

use PHPUnit\Framework\TestCase;
use Modules\Operations\Inventory\Services\AvcoValuationCalculator;

class AvcoValuationCalculatorTest extends TestCase
{
    private AvcoValuationCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new AvcoValuationCalculator();
    }

    public function test_it_returns_zero_when_new_total_quantity_is_zero_or_negative()
    {
        // Formula returns 0.0 if newTotalQty <= 0
        $this->assertSame(0.0, $this->calculator->calculate(0.0, 0.0, 0.0, 0.0));
        $this->assertSame(0.0, $this->calculator->calculate(10.0, 5.0, -15.0, -75.0));
    }

    public function test_it_calculates_wac_with_no_prior_stock()
    {
        // old_qty = 0, old_wac = 0, receipt_qty = 10, receipt_value = 150 (unit cost 15)
        // new_qty = 10, new_wac = (0 + 150) / 10 = 15.0
        $result = $this->calculator->calculate(0.0, 0.0, 10.0, 150.0);

        $this->assertSame(15.0, $result);
    }

    public function test_it_calculates_wac_with_positive_prior_stock()
    {
        // old_qty = 10, old_wac = 10.0
        // receipt_qty = 5, receipt_value = 100.0 (unit cost 20)
        // total value = (10 * 10) + 100 = 200.0
        // new_qty = 15
        // new_wac = 200 / 15 = 13.333333333333334
        $result = $this->calculator->calculate(10.0, 10.0, 5.0, 100.0);

        $this->assertEqualsWithDelta(13.3333333333, $result, 0.0001);
    }

    public function test_it_calculates_wac_with_aggregated_receipt_lines()
    {
        // Formula aggregates lines before calculation:
        // old_qty = 5, old_wac = 10.0
        // receipt_qty = 5 + 5 = 10
        // receipt_value = (5 * 12) + (5 * 14) = 60 + 70 = 130.0
        // new_qty = 15
        // total_value = 50 + 130 = 180
        // new_wac = 180 / 15 = 12.0
        $result = $this->calculator->calculate(5.0, 10.0, 10.0, 130.0);

        $this->assertSame(12.0, $result);
    }
}
