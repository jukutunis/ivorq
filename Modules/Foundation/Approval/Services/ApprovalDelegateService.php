<?php

namespace Modules\Foundation\Approval\Services;

use Modules\Foundation\Approval\Repositories\ApprovalDelegateRepository;

class ApprovalDelegateService
{
    public function __construct(private ApprovalDelegateRepository $delegateRepo)
    {
    }

    /**
     * Resolves the actual user ID to assign, checking if they have an active delegate.
     */
    public function resolveFinalUserId(string $originalUserId): string
    {
        $delegate = $this->delegateRepo->getActiveDelegateForUser($originalUserId);
        
        if ($delegate) {
            // Future feature: prevent circular delegation here.
            // For now, simple 1-level proxy resolution.
            return $delegate->delegate_id;
        }

        return $originalUserId;
    }
}
