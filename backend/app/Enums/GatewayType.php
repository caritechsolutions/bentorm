<?php

namespace App\Enums;

enum GatewayType: string
{
    case Payment = 'payment';
    case Sms = 'sms';
    case Email = 'email';
}
