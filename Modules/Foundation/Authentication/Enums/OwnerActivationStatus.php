<?php

namespace Modules\Foundation\Authentication\Enums;

enum OwnerActivationStatus: string
{
    case Invited = 'INVITED';
    case EmailVerified = 'EMAIL_VERIFIED';
    case PasswordEstablished = 'PASSWORD_ESTABLISHED';
    case MfaEnrolling = 'MFA_ENROLLING';
    case MfaEnrolled = 'MFA_ENROLLED';
    case Active = 'ACTIVE';

    public function next(): ?self
    {
        return match ($this) {
            self::Invited => self::EmailVerified,
            self::EmailVerified => self::PasswordEstablished,
            self::PasswordEstablished => self::MfaEnrolling,
            self::MfaEnrolling => self::MfaEnrolled,
            self::MfaEnrolled => self::Active,
            self::Active => null,
        };
    }
}
