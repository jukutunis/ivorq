<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class CostAvcoState extends Model
{
    use HasUlid;

    protected $table = 'cost_avco_states';

    protected $guarded = [];

    protected $casts = [
        // Decimal state — consistent with CostLedgerEntry decimal:4 convention
        'on_hand_quantity'                 => 'decimal:4',
        'carrying_value'                   => 'decimal:4',
        'weighted_average_unit_cost'       => 'decimal:4',
        'unresolved_provisional_quantity'  => 'decimal:4',

        // Last applied sequence
        'last_valuation_sequence'          => 'integer',
        'last_valuation_business_date'     => 'date',

        // Timestamps
        'created_at'                       => 'datetime',
        'updated_at'                       => 'datetime',
    ];
}
