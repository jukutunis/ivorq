<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class PurchaseRequest extends Model
{
    use HasFactory, HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $table = 'purchase_requests';

    protected $fillable = [
        'property_id',
        'request_no',
        'department_id',
        'requester_id',
        'required_date',
        'currency_code',
        'exchange_rate',
        'estimated_total',
        'status',
        'remarks',
    ];

    protected $casts = [
        'required_date' => 'date',
        'exchange_rate' => 'decimal:4',
        'estimated_total' => 'decimal:2',
        'status' => PurchaseRequestStatusEnum::class,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class, 'purchase_request_id');
    }

    protected static function newFactory()
    {
        return \Modules\Operations\Purchasing\Database\Factories\PurchaseRequestFactory::new();
    }
}
