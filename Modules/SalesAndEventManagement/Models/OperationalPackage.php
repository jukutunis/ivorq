<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\SalesAndEventManagement\Enums\OperationalPackageTypeEnum;
use Modules\SalesAndEventManagement\Enums\RevenueClassificationEnum;
use Shared\Traits\HasAuditColumns;

class OperationalPackage extends Model
{
    use HasUlids, SoftDeletes, HasAuditColumns;

    protected $table = 'operational_packages';

    protected $fillable = [
        'event_execution_template_id',
        'name',
        'package_type',
        'revenue_classification',
        'is_active',
    ];

    protected $casts = [
        'package_type' => OperationalPackageTypeEnum::class,
        'revenue_classification' => RevenueClassificationEnum::class,
        'is_active' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(EventExecutionTemplate::class, 'event_execution_template_id');
    }

    public function venueSetupSections(): HasMany
    {
        return $this->hasMany(VenueSetupSection::class, 'operational_package_id');
    }

    public function fbSections(): HasMany
    {
        return $this->hasMany(FBSection::class, 'operational_package_id');
    }

    public function avSections(): HasMany
    {
        return $this->hasMany(AVSection::class, 'operational_package_id');
    }

    public function billingSections(): HasMany
    {
        return $this->hasMany(BillingSection::class, 'operational_package_id');
    }

    public function specialRequestSections(): HasMany
    {
        return $this->hasMany(SpecialRequestSection::class, 'operational_package_id');
    }

    public function taskSections(): HasMany
    {
        return $this->hasMany(TaskSection::class, 'operational_package_id');
    }

    public function staffingSections(): HasMany
    {
        return $this->hasMany(StaffingSection::class, 'operational_package_id');
    }
}
