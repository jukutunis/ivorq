<?php

namespace Modules\Foundation\Approval\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Foundation\Approval\Models\ApprovalRequest;

class ApprovalRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public ApprovalRequest $approvalRequest)
    {
    }
}
