<?php

namespace Modules\SalesAndEventManagement\Enums;

enum OpportunitySourceEnum: string
{
    case Website = 'WEBSITE';
    case Email = 'EMAIL';
    case Phone = 'PHONE';
    case WalkIn = 'WALK_IN';
    case TravelAgent = 'TRAVEL_AGENT';
    case CorporateReferral = 'CORPORATE_REFERRAL';
    case WeddingPlanner = 'WEDDING_PLANNER';
    case SocialMedia = 'SOCIAL_MEDIA';
}
