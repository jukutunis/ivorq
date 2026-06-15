<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LostBusiness extends Model
{
    use HasUlids;

    protected $fillable = [
        'opportunity_id',
        'lost_reason',
        'lost_competitor',
        'lost_date',
        'lost_price',
        'lost_venue',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'lost_date' => 'date',
        'lost_price' => 'decimal:4',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
