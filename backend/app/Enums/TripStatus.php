<?php

namespace App\Enums;

enum TripStatus: string
{
    case PendingPayment = 'pending_payment'; // card hold awaiting authorisation (e.g. 3-D Secure)
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Arrived = 'arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoDrivers = 'no_drivers';

    /** Statuses in which a trip is still "live" for rider and driver. */
    public static function active(): array
    {
        return [self::PendingPayment, self::Requested, self::Accepted, self::Arrived, self::InProgress];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    /** Allowed forward transitions. */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::PendingPayment => [self::Requested, self::Cancelled],
            self::Requested => [self::Accepted, self::Cancelled, self::NoDrivers],
            self::Accepted => [self::Arrived, self::Cancelled],
            self::Arrived => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            default => [],
        }, true);
    }
}
