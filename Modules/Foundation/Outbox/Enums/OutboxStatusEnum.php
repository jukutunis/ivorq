<?php

namespace Modules\Foundation\Outbox\Enums;

enum OutboxStatusEnum: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
