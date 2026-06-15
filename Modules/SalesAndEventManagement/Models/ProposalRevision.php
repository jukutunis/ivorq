<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalRevision extends Model
{
    use HasUlids;

    protected $fillable = [
        'proposal_id',
        'revision_number',
        'details',
        'created_by',
        'updated_by',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}
