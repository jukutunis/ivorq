<?php

namespace Tests\Postgres\Foundation\Outbox;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;

class OutboxMessagePersistenceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private OutboxRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(OutboxRepository::class);
    }

    public function test_pending_outbox_message_persists(): void
    {
        $sourceTxId = (string) Str::ulid();
        $idemKey = 'idem-key-' . Str::uuid();

        $message = $this->repository->createPending([
            'topic' => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $sourceTxId,
            'payload' => ['transactionId' => $sourceTxId],
            'idempotency_key' => $idemKey,
        ]);

        $this->assertNotNull($message->id);
        $this->assertEquals(26, strlen($message->id));
        $this->assertEquals('inventory.transaction.posted', $message->topic);
        $this->assertEquals($sourceTxId, $message->source_inventory_transaction_id);
        $this->assertEquals(['transactionId' => $sourceTxId], $message->payload);
        $this->assertEquals(OutboxStatusEnum::Pending, $message->status);
        $this->assertEquals(0, $message->attempts);
        $this->assertNull($message->last_error);
        $this->assertNull($message->delivered_at);

        $this->assertDatabaseHas('outbox_messages', [
            'id' => $message->id,
            'topic' => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $sourceTxId,
            'idempotency_key' => $idemKey,
        ]);
    }

    public function test_idempotency_key_rejects_duplicate(): void
    {
        $sourceTxId1 = (string) Str::ulid();
        $sourceTxId2 = (string) Str::ulid();
        $idemKey = 'idem-shared-key';

        $this->repository->createPending([
            'topic' => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $sourceTxId1,
            'payload' => ['transactionId' => $sourceTxId1],
            'idempotency_key' => $idemKey,
        ]);

        $this->assertDatabaseCount('outbox_messages', 1);

        $this->expectException(QueryException::class);

        try {
            $this->repository->createPending([
                'topic' => 'inventory.transaction.posted',
                'source_inventory_transaction_id' => $sourceTxId2,
                'payload' => ['transactionId' => $sourceTxId2],
                'idempotency_key' => $idemKey,
            ]);
        } finally {
            $this->assertDatabaseCount('outbox_messages', 1);
        }
    }

    public function test_source_transaction_plus_topic_rejects_duplicate_intent(): void
    {
        $sourceTxId = (string) Str::ulid();
        $topic = 'inventory.transaction.posted';

        $this->repository->createPending([
            'topic' => $topic,
            'source_inventory_transaction_id' => $sourceTxId,
            'payload' => ['transactionId' => $sourceTxId],
            'idempotency_key' => 'idem-key-1',
        ]);

        $this->assertDatabaseCount('outbox_messages', 1);

        $this->expectException(QueryException::class);

        try {
            $this->repository->createPending([
                'topic' => $topic,
                'source_inventory_transaction_id' => $sourceTxId,
                'payload' => ['transactionId' => $sourceTxId],
                'idempotency_key' => 'idem-key-2', // Different idempotency key, but same tx + topic
            ]);
        } finally {
            $this->assertDatabaseCount('outbox_messages', 1);
        }
    }

    public function test_different_topic_remains_independently_representable(): void
    {
        $sourceTxId = (string) Str::ulid();

        $this->repository->createPending([
            'topic' => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $sourceTxId,
            'payload' => ['transactionId' => $sourceTxId],
            'idempotency_key' => 'idem-key-a',
        ]);

        $this->repository->createPending([
            'topic' => 'inventory.transaction.adjusted',
            'source_inventory_transaction_id' => $sourceTxId,
            'payload' => ['transactionId' => $sourceTxId],
            'idempotency_key' => 'idem-key-b',
        ]);

        $this->assertDatabaseCount('outbox_messages', 2);
    }
}
