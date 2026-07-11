<?php

namespace Modules\Finance\AccountsReceivable\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Shared\Traits\HasUlid;

class GuestArTransferDecision extends Model
{
    use HasUlid;
    public $timestamps = false;
    protected $fillable = [];
    protected $guarded = ['*'];
    protected $casts = ['decision_type' => GuestArTransferDecisionTypeEnum::class, 'decided_at' => 'datetime', 'source_snapshot' => 'array', 'created_at' => 'datetime'];
    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function request(): BelongsTo { return $this->belongsTo(GuestArTransferRequest::class, 'guest_ar_transfer_request_id'); }
    public function reversesDecision(): BelongsTo { return $this->belongsTo(self::class, 'reverses_decision_id'); }
    public function decidedBy(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
    protected static function booted(): void { static::deleting(fn () => throw new DomainException('Guest AR transfer decisions are immutable.')); }
}
