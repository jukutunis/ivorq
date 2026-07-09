<?php

namespace Modules\Operations\FrontDesk\Enums;

enum FrontDeskDepartureCheckoutEligibilityStatusEnum: string
{
    case CheckoutEligible = 'CHECKOUT_ELIGIBLE';
    case CheckoutBlocked = 'CHECKOUT_BLOCKED';
    case CheckoutReviewed = 'CHECKOUT_REVIEWED';
}
