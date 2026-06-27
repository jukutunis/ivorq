<?php

namespace Modules\Foundation\Outbox\Repositories;

use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;

class OutboxRepository
{
    public function createPending(array $attributes): OutboxMessage
    {
        $attributes['status'] = OutboxStatusEnum::Pending;
        $attributes['attempts'] = 0;

        return OutboxMessage::create($attributes);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?OutboxMessage
    {
        return OutboxMessage::where('idempotency_key', $idempotencyKey)->first();
    }

    public function findById(string $id): ?OutboxMessage
    {
        return OutboxMessage::find($id);
    }

    public function incrementAttempts(string $id): OutboxMessage
    {
        $message = OutboxMessage::findOrFail($id);

        if ($message->status === OutboxStatusEnum::Delivered) {
            throw new \RuntimeException("Outbox message already delivered.");
        }

        $message->attempts += 1;
        $message->save();

        return $message;
    }

    public function markDelivered(string $id): OutboxMessage
    {
        $message = OutboxMessage::findOrFail($id);

        if ($message->status === OutboxStatusEnum::Delivered) {
            throw new \RuntimeException("Outbox message already delivered.");
        }

        $message->status = OutboxStatusEnum::Delivered;
        $message->delivered_at = now();
        $message->last_error = null;
        $message->save();

        return $message;
    }

    public function markFailed(string $id, string $errorMessage): OutboxMessage
    {
        $message = OutboxMessage::findOrFail($id);

        if ($message->status === OutboxStatusEnum::Delivered) {
            throw new \RuntimeException("Outbox message already delivered.");
        }

        $message->status = OutboxStatusEnum::Failed;
        $message->last_error = $errorMessage;
        $message->save();

        return $message;
    }
}
