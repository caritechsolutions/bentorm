<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Phone
{
    /**
     * Normalise to E.164. Bare 7-digit Barbados numbers get +1246; 10-digit NANP numbers get +1.
     */
    public static function normalise(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        $digits = ltrim($digits, '0');

        if (strlen($digits) === 7) {
            $digits = '1246'.$digits;
        } elseif (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            throw ValidationException::withMessages(['phone' => ['Enter a valid phone number.']]);
        }

        return '+'.$digits;
    }
}
