<?php

namespace App\Enums;

enum AreaType: string
{
    case Service = 'service';
    case Zone = 'zone';
    case Blocked = 'blocked';
}
