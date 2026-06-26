<?php

namespace Modules\SalesAndEventManagement\Exceptions;

use RuntimeException;

class DistributionStateException extends RuntimeException
{
    public static function illegalTransition(string $from, string $to): self
    {
        return new self("Illegal BEO Distribution state transition: [{$from}] → [{$to}].");
    }

    public static function terminalState(string $state): self
    {
        return new self("BEO Distribution is in terminal state [{$state}] and cannot be transitioned.");
    }
}
