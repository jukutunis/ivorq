<?php

namespace Modules\Finance\CostControl\Enums;

enum CostDeliveryMode: string
{
    case Synchronous = 'SYNCHRONOUS';
    case Deferred = 'DEFERRED';
}
