<?php

namespace Modules\Finance\Payables\Enums;

enum PaymentProposalStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case CANCELLED = 'CANCELLED';
}
