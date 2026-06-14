<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Shared\Traits\HasUlid;

class JournalCandidateLine extends Model
{
    use HasUlid;

    protected $fillable = [
        'journal_candidate_id',
        'operational_identity',
        'entry_type',
        'amount',
        'cost_center_id',
        'notes',
    ];

    protected $casts = [
        'operational_identity' => OperationalIdentityEnum::class,
        'entry_type' => EntryTypeEnum::class,
        'amount' => 'decimal:4',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(JournalCandidate::class, 'journal_candidate_id');
    }
}
