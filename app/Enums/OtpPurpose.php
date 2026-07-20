<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case PhoneVerification = 'verify';
    case PasswordReset = 'reset';
}
