<?php

namespace Modules\Operations\PMS\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;

class GuestDepositApplication extends Model
{
    use HasUlid;
    public $timestamps = false;
    protected $fillable = [];
    protected $guarded = ['*'];
    protected $casts = ['amount' => 'decimal:2', 'applied_at' => 'datetime', 'source_snapshot' => 'array', 'created_at' => 'datetime'];
    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function deposit(): BelongsTo { return $this->belongsTo(GuestDepositTransaction::class, 'guest_deposit_transaction_id'); }
    public function folio(): BelongsTo { return $this->belongsTo(Folio::class); }
    public function appliedBy(): BelongsTo { return $this->belongsTo(User::class, 'applied_by'); }
    protected static function booted(): void { static::deleting(fn () => throw new DomainException('Guest deposit applications are immutable.')); }
}
