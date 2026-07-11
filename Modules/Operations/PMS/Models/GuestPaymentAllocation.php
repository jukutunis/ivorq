<?php

namespace Modules\Operations\PMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;

class GuestPaymentAllocation extends Model
{
    use HasUlid;

    public $timestamps = false;

    protected $fillable = [];

    protected $guarded = ['*'];

    protected $casts = [
        'amount' => 'decimal:2',
        'allocated_at' => 'datetime',
        'source_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(GuestPaymentTransaction::class, 'guest_payment_transaction_id');
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }
}
