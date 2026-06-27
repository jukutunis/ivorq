<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class CostTransferPairResolution extends Model
{
    use HasUlid;

    protected $table = 'cost_transfer_pair_resolutions';

    protected $guarded = [];

    protected $casts = [
        'source_valuation_sequence'      => 'integer',
        'destination_valuation_sequence' => 'integer',
        'frozen_source_unit_cost'        => 'decimal:4',
        'created_at'                     => 'datetime',
        'updated_at'                     => 'datetime',
    ];
}
