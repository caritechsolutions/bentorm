<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment'; // card hold waiting for 3-D Secure
    case Placed = 'placed';                  // waiting for the store to accept
    case Accepted = 'accepted';              // store is preparing it
    case Ready = 'ready';                    // packed, waiting for the driver
    case PickedUp = 'picked_up';             // the driver has it (store confirmed the hand-over)
    case Delivered = 'delivered';            // customer's PIN entered at the door; card captured
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';                // store didn't answer in time

    public function isActive(): bool
    {
        return in_array($this, [self::PendingPayment, self::Placed, self::Accepted, self::Ready, self::PickedUp], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Waiting for card confirmation',
            self::Placed => 'Waiting for the store',
            self::Accepted => 'Being prepared',
            self::Ready => 'Ready',
            self::PickedUp => 'On the way',
            self::Delivered => 'Delivered',
            self::Declined => 'Declined by the store',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Store did not respond',
        };
    }
}
