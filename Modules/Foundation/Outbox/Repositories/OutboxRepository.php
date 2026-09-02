<?php

namespace Modules\Foundation\Outbox\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use RuntimeException;

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

    public function findForUpdate(string $id): ?OutboxMessage
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'OutboxRepository::findForUpdate requires an active outer transaction.'
            );
        }

        return OutboxMessage::whereKey($id)
            ->lockForUpdate()
            ->first();
    }

    /** @return array<string, OutboxMessage> */
    public function findManyForUpdate(array $ids): array
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        $messages = OutboxMessage::whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        if (count($messages) !== count($ids)) {
            throw new RuntimeException('CC_P01E_OUTBOX_LOCK_SET_INCOMPLETE');
        }

        return $messages;
    }

    public function markDeliveredWithinTransaction(
        OutboxMessage $message,
        \DateTimeInterface $deliveredAt,
    ): OutboxMessage {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }
        if ($message->status !== OutboxStatusEnum::Pending
            && $message->status !== OutboxStatusEnum::Failed) {
            throw new RuntimeException('CC_P01E_OUTBOX_NOT_PROCESSABLE_FOR_DELIVERY');
        }

        $message->status = OutboxStatusEnum::Delivered;
        $message->delivered_at = $deliveredAt;
        $message->last_error = null;
        $message->save();

        return $message;
    }

    public function markFailedWithinTransaction(
        OutboxMessage $message,
        string $failureCode,
    ): OutboxMessage {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }
        if ($message->status === OutboxStatusEnum::Delivered) {
            throw new RuntimeException('CC_P01E_DELIVERED_OUTBOX_CANNOT_FAIL');
        }
        if (! preg_match('/^[A-Z0-9_]{1,96}$/', $failureCode)) {
            throw new RuntimeException('CC_P01E_OUTBOX_FAILURE_CODE_INVALID');
        }

        $message->status = OutboxStatusEnum::Failed;
        $message->last_error = $failureCode;
        $message->save();

        return $message;
    }

    public function incrementAttempts(string $id): OutboxMessage
    {
        $message = OutboxMessage::findOrFail($id);

        if ($message->status === OutboxStatusEnum::Delivered) {
            throw new RuntimeException('Outbox message already delivered.');
        }

        $message->attempts += 1;
        $message->save();

        return $message;
    }

    public function markDelivered(string $id): OutboxMessage
    {
        $message = OutboxMessage::findOrFail($id);

        if ($message->status === OutboxStatusEnum::Delivered) {
            throw new RuntimeException('Outbox message already delivered.');
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
            throw new RuntimeException('Outbox message already delivered.');
        }

        $message->status = OutboxStatusEnum::Failed;
        $message->last_error = $errorMessage;
        $message->save();

        return $message;
    }
}
