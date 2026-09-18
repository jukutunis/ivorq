<?php

namespace Modules\Foundation\Property\Enums;

enum PropertyBootstrapProvisioningEnvironmentEnum: string
{
    case Operational = 'operational';
    case Rehearsal = 'rehearsal';
}
