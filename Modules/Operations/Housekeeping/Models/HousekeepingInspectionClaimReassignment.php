<?php

namespace Modules\Operations\Housekeeping\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\User\Models\User;

class HousekeepingInspectionClaimReassignment extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'housekeeping_inspection_claim_reassignments';

    protected $fillable = [
        'property_id',
        'room_inspection_id',
        'original_claimant_id',
        'replacement_claimant_id',
        'intervened_by',
        'original_ineligibility_code',
        'reason',
        'idempotency_key',
        'source_hash',
        'evidence_version',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'evidence_version' => 'integer',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function inspection()
    {
        return $this->belongsTo(RoomInspection::class, 'room_inspection_id');
    }

    public function originalClaimant()
    {
        return $this->belongsTo(User::class, 'original_claimant_id')->withTrashed();
    }

    public function replacementClaimant()
    {
        return $this->belongsTo(User::class, 'replacement_claimant_id')->withTrashed();
    }

    public function intervenor()
    {
        return $this->belongsTo(User::class, 'intervened_by')->withTrashed();
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('Housekeeping Inspection claim reassignment evidence is immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Housekeeping Inspection claim reassignment evidence cannot be deleted.');
        });
    }
}
