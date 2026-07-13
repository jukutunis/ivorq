<?php

namespace Modules\Operations\GeneralCashier\Enums;

enum GeneralCashierCheckoutObligationStatusEnum: string
{
    case CashierObligationClear = 'CASHIER_OBLIGATION_CLEAR';
    case CashierObligationBlocked = 'CASHIER_OBLIGATION_BLOCKED';
    case CashierObligationReviewRequired = 'CASHIER_OBLIGATION_REVIEW_REQUIRED';
    case CashierObligationEvidenceUnavailable = 'CASHIER_OBLIGATION_EVIDENCE_UNAVAILABLE';
}
