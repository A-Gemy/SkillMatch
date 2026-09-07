<?php

namespace App\Services;

use InvalidArgumentException;

class PhoneNumber
{
    public static function normalize(string $phone): string
    {
        $phone = preg_replace('/[\s()\-]+/u', '', trim($phone));

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        } elseif (preg_match('/^01[0125][0-9]{8}$/', $phone)) {
            $phone = '+20'.substr($phone, 1);
        } elseif (preg_match('/^05[0-9]{8}$/', $phone)) {
            $phone = '+966'.substr($phone, 1);
        } elseif (! str_starts_with($phone, '+')) {
            $phone = '+'.$phone;
        }

        if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)
            || (str_starts_with($phone, '+20') && ! preg_match('/^\+201[0125][0-9]{8}$/', $phone))
            || (str_starts_with($phone, '+966') && ! preg_match('/^\+9665[0-9]{8}$/', $phone))) {
            throw new InvalidArgumentException('Enter a valid mobile number with its country code (for example, +20 or +966).');
        }

        return $phone;
    }
}
