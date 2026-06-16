<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\SalesAndEventManagement\Enums\EventExecutionTemplateCategoryEnum;
use Modules\SalesAndEventManagement\Enums\TemplateStatusEnum;
use Shared\Traits\HasAuditColumns;

class EventExecutionTemplate extends Model
{
    use HasUlids, SoftDeletes, HasAuditColumns;

    protected $table = 'event_execution_templates';

    protected $fillable = [
        'company_id',
        'property_id',
        'name',
        'category',
        'status',
        'revision_number',
        'previous_template_id',
        'approved_by',
        'approved_at',
        'published_at',
    ];

    protected $casts = [
        'category' => EventExecutionTemplateCategoryEnum::class,
        'status' => TemplateStatusEnum::class,
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function previousTemplate(): BelongsTo
    {
        return $this->belongsTo(EventExecutionTemplate::class, 'previous_template_id');
    }

    public function operationalPackages(): HasMany
    {
        return $this->hasMany(OperationalPackage::class, 'event_execution_template_id');
    }
}
