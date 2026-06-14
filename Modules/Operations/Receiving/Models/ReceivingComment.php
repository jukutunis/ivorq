<?php

namespace Modules\Operations\Receiving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;

class ReceivingComment extends Model
{
    use HasUlid, HasAuditColumns;

    protected $table = 'receiving_comments';

    protected $fillable = [
        'body',
    ];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
