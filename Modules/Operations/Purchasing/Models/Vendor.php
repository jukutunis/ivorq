<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Company;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Vendor extends Model
{
    use HasAuditColumns, HasUlid, LogsActivity, SoftDeletes;

    protected $table = 'vendors';

    protected $fillable = [
        'company_id',
        'vendor_category_id',
        'vendor_code',
        'name',
        'tax_id',
        'tax_number',
        'contact_person',
        'email',
        'phone',
        'payment_term_days',
        'credit_limit',
        'default_currency_code',
        'is_active',
        'is_approved',
        'performance_score',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_approved' => 'boolean',
        'performance_score' => 'decimal:2',
        'payment_term_days' => 'integer',
        'credit_limit' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(VendorCategory::class, 'vendor_category_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class, 'vendor_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), $companyId);
    }
}
