<?php

namespace Modules\Operations\Engineering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Engineering\Enums\EngineeringChecklistTypeEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class EngineeringChecklist extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'title',
        'description',
        'checklist_type',
        'is_active',
    ];

    protected $casts = [
        'checklist_type' => EngineeringChecklistTypeEnum::class,
        'is_active'      => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EngineeringChecklistItem::class, 'engineering_checklist_id')
            ->orderBy('sort_order');
    }
}
