<?php

namespace Tests\Postgres\Foundation\Outbox;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;

class OutboxMessageDeliveryStateTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private OutboxRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(OutboxRepository::class);
    }

    private function createPendingMessage(): OutboxMessage
    {
        $sourceTxId = (string) Str::ulid();
        return $this->repository->createPending([
            'topic' => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $sourceTxId,
            'payload' => ['transactionId' => $sourceTxId],
            'idempotency_key' => 'idem-' . Str::uuid(),
        ]);
    }

    public function test_pending_message_records_an_attempt(): void
    {
        $message = $this->createPendingMessage();

        $this->assertEquals(OutboxStatusEnum::Pending, $message->status);
        $this->assertEquals(0, $message->attempts);
        $this->assertNull($message->last_error);
        $this->assertNull($message->delivered_at);

        $returned = $this->repository->incrementAttempts($message->id);

        $this->assertEquals($message->id, $returned->id);
        $this->assertEquals(OutboxStatusEnum::Pending, $returned->status);
        $this->assertEquals(1, $returned->attempts);
        $this->assertNull($returned->last_error);
        $this->assertNull($returned->delivered_at);

        // Verify in DB
        $fresh = $message->fresh();
        $this->assertEquals(OutboxStatusEnum::Pending, $fresh->status);
        $this->assertEquals(1, $fresh->attempts);
    }

    public function test_pending_message_can_become_delivered(): void
    {
        $message = $this->createPendingMessage();

        $this->repository->incrementAttempts($message->id);

        $delivered = $this->repository->markDelivered($message->id);

        $this->assertEquals(OutboxStatusEnum::Delivered, $delivered->status);
        $this->assertEquals(1, $delivered->attempts);
        $this->assertNull($delivered->last_error);
        $this->assertNotNull($delivered->delivered_at);

        // Verify in DB
        $fresh = $message->fresh();
        $this->assertEquals(OutboxStatusEnum::Delivered, $fresh->status);
        $this->assertEquals(1, $fresh->attempts);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertNull($fresh->last_error);
    }

    public function test_failure_is_durable_and_recoverable(): void
    {
        $message = $this->createPendingMessage();

        $this->repository->incrementAttempts($message->id);

        $failed = $this->repository->markFailed($message->id, 'Simulated connection timeout');

        $this->assertEquals(OutboxStatusEnum::Failed, $failed->status);
        $this->assertEquals(1, $failed->attempts);
        $this->assertEquals('Simulated connection timeout', $failed->last_error);
        $this->assertNull($failed->delivered_at);

        // Verify in DB
        $fresh = $message->fresh();
        $this->assertEquals(OutboxStatusEnum::Failed, $fresh->status);
        $this->assertEquals(1, $fresh->attempts);
        $this->assertEquals('Simulated connection timeout', $fresh->last_error);
        $this->assertNull($fresh->delivered_at);

        // Prove future recovery
        $this->repository->incrementAttempts($message->id);
        $delivered = $this->repository->markDelivered($message->id);

        $this->assertEquals(OutboxStatusEnum::Delivered, $delivered->status);
        $this->assertEquals(2, $delivered->attempts);
        $this->assertNull($delivered->last_error);
        $this->assertNotNull($delivered->delivered_at);

        // Verify in DB
        $freshRecovered = $message->fresh();
        $this->assertEquals(OutboxStatusEnum::Delivered, $freshRecovered->status);
        $this->assertEquals(2, $freshRecovered->attempts);
        $this->assertNull($freshRecovered->last_error);
        $this->assertNotNull($freshRecovered->delivered_at);
    }

    public function test_delivered_message_is_terminal(): void
    {
        $message = $this->createPendingMessage();

        $this->repository->markDelivered($message->id);

        $deliveredAt = $message->fresh()->delivered_at;

        // 1. incrementAttempts fails closed
        try {
            $this->repository->incrementAttempts($message->id);
            $this->fail('Expected RuntimeException not thrown for incrementAttempts on delivered message');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already delivered', $e->getMessage());
        }

        $fresh = $message->fresh();
        $this->assertEquals(OutboxStatusEnum::Delivered, $fresh->status);
        $this->assertEquals(0, $fresh->attempts);
        $this->assertNull($fresh->last_error);
        $this->assertEquals($deliveredAt, $fresh->delivered_at);

        // 2. markDelivered fails closed
        try {
            $this->repository->markDelivered($message->id);
            $this->fail('Expected RuntimeException not thrown for markDelivered on delivered message');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already delivered', $e->getMessage());
        }

        $fresh = $message->fresh();
        $this->assertEquals(OutboxStatusEnum::Delivered, $fresh->status);
        $this->assertEquals(0, $fresh->attempts);
        $this->assertNull($fresh->last_error);
        $this->assertEquals($deliveredAt, $fresh->delivered_at);

        // 3. markFailed fails closed
        try {
            $this->repository->markFailed($message->id, 'Another error');
            $this->fail('Expected RuntimeException not thrown for markFailed on delivered message');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already delivered', $e->getMessage());
        }

        $fresh = $message->fresh();
        $this->assertEquals(OutboxStatusEnum::Delivered, $fresh->status);
        $this->assertEquals(0, $fresh->attempts);
        $this->assertNull($fresh->last_error);
        $this->assertEquals($deliveredAt, $fresh->delivered_at);
    }
}
