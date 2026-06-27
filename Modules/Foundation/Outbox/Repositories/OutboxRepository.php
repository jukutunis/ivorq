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
}
