<?php

namespace Modules\Operations\Engineering\Enums;

enum TechnicianRoleEnum: string
{
    case Lead      = 'lead';
    case Assistant = 'assistant';

    public function label(): string
    {
        return match($this) {
            self::Lead      => 'Lead Technician',
            self::Assistant => 'Assistant',
        };
    }
}
