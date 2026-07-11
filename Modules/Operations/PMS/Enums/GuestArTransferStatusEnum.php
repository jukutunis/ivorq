<?php

namespace Modules\Operations\PMS\Enums;

enum GuestArTransferStatusEnum: string
{
    case Requested = 'REQUESTED';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case Reversed = 'REVERSED';
}
