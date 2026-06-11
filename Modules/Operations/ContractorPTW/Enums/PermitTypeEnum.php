<?php

namespace Modules\Operations\ContractorPTW\Enums;

enum PermitTypeEnum: string
{
    case HOT_WORK = 'hot_work';
    case ELECTRICAL = 'electrical';
    case HEIGHT = 'height';
    case CONFINED_SPACE = 'confined_space';
    case EXCAVATION = 'excavation';
    case CHEMICAL = 'chemical';
    case LOTO = 'loto';
    case GENERAL = 'general';
    case EMERGENCY = 'emergency';
}
