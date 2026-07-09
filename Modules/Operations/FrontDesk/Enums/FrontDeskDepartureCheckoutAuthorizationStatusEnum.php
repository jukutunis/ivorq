<?php

namespace Modules\Operations\FrontDesk\Enums;

enum FrontDeskDepartureCheckoutAuthorizationStatusEnum: string
{
    case CheckoutAuthorizationReady = 'CHECKOUT_AUTHORIZATION_READY';
    case CheckoutAuthorizationBlocked = 'CHECKOUT_AUTHORIZATION_BLOCKED';
    case CheckoutAuthorizationReviewed = 'CHECKOUT_AUTHORIZATION_REVIEWED';
}
