<?php

namespace Modules\Foundation\Approval\Repositories;

use Modules\Foundation\Approval\Models\ApprovalDelegate;

class ApprovalDelegateRepository
{
    public function getActiveDelegateForUser(string $userId): ?ApprovalDelegate
    {
        return ApprovalDelegate::where('delegator_id', $userId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')
                      ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                      ->orWhere('ends_at', '>=', now());
            })
            ->first();
    }
}
