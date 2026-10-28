<?php

namespace App\Enums;

enum FleetStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Rejected => 'Not approved',
            self::Suspended => 'Suspended',
        };
    }
}
