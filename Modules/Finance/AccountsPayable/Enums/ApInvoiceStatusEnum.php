<?php

namespace Modules\Finance\AccountsPayable\Enums;

enum ApInvoiceStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING_REVIEW = 'pending_review';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case POSTED = 'posted';
    case VOIDED = 'voided';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => in_array($target, [self::PENDING_REVIEW, self::VOIDED]),
            self::PENDING_REVIEW => in_array($target, [self::APPROVED, self::REJECTED, self::DRAFT]),
            self::APPROVED => in_array($target, [self::POSTED, self::VOIDED]),
            self::REJECTED => in_array($target, [self::DRAFT]),
            self::POSTED => in_array($target, [self::VOIDED]),
            self::VOIDED => false,
        };
    }
}
