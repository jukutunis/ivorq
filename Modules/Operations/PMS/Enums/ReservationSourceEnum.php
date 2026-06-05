<?php

namespace Modules\Operations\PMS\Enums;

enum ReservationSourceEnum: string
{
    case Direct         = 'direct';
    case Ota            = 'ota';
    case Phone          = 'phone';
    case WalkIn         = 'walk_in';
    case Corporate      = 'corporate';
    case ChannelManager = 'channel_manager';

    public function label(): string
    {
        return match ($this) {
            self::Direct         => 'Direct',
            self::Ota            => 'OTA',
            self::Phone          => 'Phone',
            self::WalkIn         => 'Walk-In',
            self::Corporate      => 'Corporate',
            self::ChannelManager => 'Channel Manager',
        };
    }
}
