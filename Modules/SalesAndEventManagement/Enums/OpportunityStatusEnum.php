<?php

namespace Modules\SalesAndEventManagement\Enums;

enum OpportunityStatusEnum: string
{
    case Inquiry = 'INQUIRY';
    case Qualified = 'QUALIFIED';
    case ProposalSent = 'PROPOSAL_SENT';
    case Negotiation = 'NEGOTIATION';
    case Definite = 'DEFINITE';
    case Lost = 'LOST';
    case Cancelled = 'CANCELLED';
}
