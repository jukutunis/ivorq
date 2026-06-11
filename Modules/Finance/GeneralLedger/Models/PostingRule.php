<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostingRule extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $table = 'gl_posting_rules';

    protected $fillable = [
        'property_id',
        'posting_profile_id',
        'account_role',
        'account_id',
        'department_id',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PostingProfile::class, 'posting_profile_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
