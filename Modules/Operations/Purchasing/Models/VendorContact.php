<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class VendorContact extends Model
{
    use HasUlid, HasAuditColumns, SoftDeletes;

    protected $table = 'vendor_contacts';

    protected $fillable = [
        'vendor_id',
        'contact_name',
        'email',
        'phone',
        'position',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
