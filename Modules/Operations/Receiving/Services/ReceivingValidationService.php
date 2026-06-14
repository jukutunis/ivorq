<?php

namespace Modules\Operations\Receiving\Services;

use Shared\Exceptions\BusinessLogicException;

class ReceivingValidationService
{
    public function validateCreation(array $data): void
    {
        if (empty($data['vendor_id'])) {
            throw new BusinessLogicException('Vendor is required for receiving.');
        }

        if (isset($data['lines']) && count($data['lines']) > 0) {
            foreach ($data['lines'] as $line) {
                if (($line['received_quantity'] ?? 0) <= 0) {
                    throw new BusinessLogicException('Received quantity must be greater than zero.');
                }
            }
        }
    }
}
