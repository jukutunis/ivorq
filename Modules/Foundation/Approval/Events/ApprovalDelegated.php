<?php

namespace Modules\Foundation\Approval\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\User\Models\User;

class ApprovalDelegated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ApprovalRequest $approvalRequest,
        public User $delegatedFrom,
        public User $delegatedTo
    ) {}
}
