<?php

namespace App\Enums;

enum UserRole: string
{
    case Rider = 'rider';
    case Driver = 'driver';
    case Admin = 'admin';
}
