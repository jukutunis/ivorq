<?php

namespace Modules\Finance\Banking\Enums;

enum ControlledBankStatementLineDirectionEnum: string
{
    case INFLOW = 'INFLOW';
    case OUTFLOW = 'OUTFLOW';
}
