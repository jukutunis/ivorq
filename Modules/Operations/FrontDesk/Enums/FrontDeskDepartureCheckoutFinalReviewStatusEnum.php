<?php

namespace Modules\Operations\FrontDesk\Enums;

enum FrontDeskDepartureCheckoutFinalReviewStatusEnum: string
{
    case CheckoutFinalReviewReady = 'CHECKOUT_FINAL_REVIEW_READY';
    case CheckoutFinalReviewBlocked = 'CHECKOUT_FINAL_REVIEW_BLOCKED';
    case CheckoutFinalReviewReviewed = 'CHECKOUT_FINAL_REVIEW_REVIEWED';
}
