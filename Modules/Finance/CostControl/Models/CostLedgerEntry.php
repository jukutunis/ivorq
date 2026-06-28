<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class CostLedgerEntry extends Model
{
    use HasUlid;

    protected $table = 'cost_ledger_entries';

    // Append-only: only created_at, no updated_at.
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        // Sequence
        'valuation_sequence'               => 'integer',

        // Temporal
        'business_date'                    => 'date',
        'created_at'                       => 'datetime',

        // AVCO state snapshot — decimal:4 matches AvcoDecimal::SCALE = 4
        // and CostAvcoState on_hand_quantity / carrying_value precision
        'quantity_before'                  => 'decimal:4',
        'quantity_after'                   => 'decimal:4',
        'carrying_value_before'            => 'decimal:4',
        'carrying_value_after'             => 'decimal:4',
        'weighted_average_unit_cost_after' => 'decimal:4',
    ];
}
