<?php

namespace App\Enums;

enum FareRuleKind: string
{
    case TimeMultiplier = 'time_multiplier';
    case Surge = 'surge';
    case ZoneFee = 'zone_fee';
    case FixedRoute = 'fixed_route';
}
