<?php

namespace Tests\Unit\Finance\CostControl;

use PHPUnit\Framework\TestCase;
use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;
use InvalidArgumentException;

class CostLedgerEntryIntentTest extends TestCase
{
    public function test_intent_valid_dapat_dibuat()
    {
        $intent = new CostLedgerEntryIntent(
            propertyId: 'prop_01',
            sourceInventoryTransactionId: 'txn_01',
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idemp_123',
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('10.0'),
            unitCost: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('15.0'),
            valueDelta: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('150.0'),
            businessDate: '2026-06-22',
            occurredAt: '2026-06-22 10:00:00'
        );

        $this->assertEquals('prop_01', $intent->propertyId);
        $this->assertEquals(1, $intent->entrySequence);
    }

    public function test_intent_menolak_entry_sequence_nol_atau_negatif()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("entrySequence must be positive");

        new CostLedgerEntryIntent(
            propertyId: 'prop_01',
            sourceInventoryTransactionId: 'txn_01',
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idemp_123',
            entrySequence: 0,
            currencyCode: 'USD',
            quantityDelta: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('10.0'),
            unitCost: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('15.0'),
            valueDelta: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('150.0'),
            businessDate: '2026-06-22',
            occurredAt: '2026-06-22 10:00:00'
        );
    }

    public function test_intent_menolak_source_transaction_reference_kosong()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("sourceInventoryTransactionId cannot be blank");

        new CostLedgerEntryIntent(
            propertyId: 'prop_01',
            sourceInventoryTransactionId: '',
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idemp_123',
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('10.0'),
            unitCost: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('15.0'),
            valueDelta: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('150.0'),
            businessDate: '2026-06-22',
            occurredAt: '2026-06-22 10:00:00'
        );
    }

    public function test_intent_immutable_setelah_dibuat()
    {
        $intent = new CostLedgerEntryIntent(
            propertyId: 'prop_01',
            sourceInventoryTransactionId: 'txn_01',
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: 'idemp_123',
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('10.0'),
            unitCost: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('15.0'),
            valueDelta: new \Modules\Finance\CostControl\ValueObjects\AvcoDecimal('150.0'),
            businessDate: '2026-06-22',
            occurredAt: '2026-06-22 10:00:00'
        );

        $reflection = new \ReflectionClass($intent);
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly());
        }
    }
}
