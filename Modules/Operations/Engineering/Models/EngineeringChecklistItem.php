<?php

namespace Modules\Operations\Engineering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class EngineeringChecklistItem extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty;

    protected $fillable = [
        'property_id',
        'engineering_checklist_id',
        'item_text',
        'sort_order',
        'is_required',
    ];

    protected $casts = [
        'sort_order'  => 'integer',
        'is_required' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(EngineeringChecklist::class, 'engineering_checklist_id');
    }
}
