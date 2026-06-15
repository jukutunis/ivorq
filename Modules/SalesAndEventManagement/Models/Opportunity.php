<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\SalesAndEventManagement\Enums\OpportunityStatusEnum;

class Opportunity extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id',
        'property_id',
        'account_id',
        'opportunity_name',
        'status',
        'opportunity_source',
        'estimated_revenue',
        'expected_event_date',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => OpportunityStatusEnum::class,
        'opportunity_source' => \Modules\SalesAndEventManagement\Enums\OpportunitySourceEnum::class,
        'estimated_revenue' => 'decimal:4',
        'expected_event_date' => 'date',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lostBusiness(): HasOne
    {
        return $this->hasOne(LostBusiness::class);
    }

    public function proposals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Proposal::class);
    }
}
