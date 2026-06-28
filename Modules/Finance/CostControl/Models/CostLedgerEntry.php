<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Modules\Operations\Inventory\Models\InventoryTransaction;

class CostLedgerEntry extends Model
{
    use HasUlid;

    public $timestamps = false; // We use created_at only, no updated_at

    protected $guarded = [];

    protected $casts = [
        'entry_sequence' => 'integer',
        'quantity_delta' => 'decimal:4',
        'unit_cost'      => 'decimal:4',
        'value_delta'    => 'decimal:4',
        'business_date'  => 'date',
        'occurred_at'    => 'datetime',
        'original_business_date' => 'date',
        'metadata'       => 'array',
        'created_at'     => 'datetime',
    ];

    public function sourceInventoryTransaction()
    {
        return $this->belongsTo(InventoryTransaction::class, 'source_inventory_transaction_id');
    }

    public function priorEntry()
    {
        return $this->belongsTo(self::class, 'prior_cost_ledger_entry_id');
    }

    public function subsequentEntries()
    {
        return $this->hasMany(self::class, 'prior_cost_ledger_entry_id');
    }
}
