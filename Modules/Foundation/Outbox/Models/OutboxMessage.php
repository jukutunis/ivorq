<?php

namespace Modules\Foundation\Outbox\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;

class OutboxMessage extends Model
{
    use HasUlid;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'status' => OutboxStatusEnum::class,
        'attempts' => 'integer',
        'delivered_at' => 'datetime',
    ];
}
