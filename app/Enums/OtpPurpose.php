<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case PhoneVerification = 'verify';
    case PasswordReset = 'reset';

    /**
     * Short phrase describing what the code is for, used in the SMS text
     * sent to the user (e.g. "Your Store API sign-up code is 123456.").
     */
    public function smsLabel(): string
    {
        return match ($this) {
            self::PhoneVerification => 'sign-up',
            self::PasswordReset => 'password reset',
        };
    }
}
