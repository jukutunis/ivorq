<?php

namespace Modules\Operations\GeneralCashier\Services\Ports;

interface GeneralCashierCheckoutObligationSnapshotProbe
{
    public function afterCashSourceRead(string $propertyId, string $frontDeskStayId): void;
}
