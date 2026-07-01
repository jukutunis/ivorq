<?php

namespace Modules\Finance\FxReference\Enums;

enum ExchangeRateEvidenceStatusEnum: string
{
    case RECORDED = 'RECORDED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
}
