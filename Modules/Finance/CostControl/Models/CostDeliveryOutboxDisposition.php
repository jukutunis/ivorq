<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Finance\CostControl\Enums\CostDeliveryDispositionClass;
use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;
use Shared\Traits\HasUlid;

class CostDeliveryOutboxDisposition extends Model
{
    use HasUlid;

    protected $guarded = [];

    protected $casts = [
        'valuation_sequence' => 'integer',
        'cost_delivery_ownership_version' => 'integer',
        'classification' => CostDeliveryDispositionClass::class,
        'processing_state' => CostDeliveryProcessingState::class,
        'classified_at' => 'immutable_datetime',
        'attempt_count' => 'integer',
        'last_attempted_at' => 'immutable_datetime',
        'is_recoverable' => 'boolean',
        'expected_sequence' => 'integer',
        'historical_excluded_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
    ];
}
