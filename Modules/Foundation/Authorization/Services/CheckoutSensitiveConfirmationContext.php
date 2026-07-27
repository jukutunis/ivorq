<?php

namespace Modules\Foundation\Authorization\Services;

use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;

final readonly class CheckoutSensitiveConfirmationContext
{
    public function __construct(
        public User $actor,
        public Company $company,
        public Property $property,
        public FrontDeskStay $stay,
        public string $checkoutIdempotencyKey,
        public string $sessionFingerprint,
    ) {}
}
