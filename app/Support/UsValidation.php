<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rule;

/**
 * US clinic form rules (NANP phone, ZIP+4, state codes, DOB bounds).
 */
final class UsValidation
{
    /** @return list<string> */
    public static function stateCodes(): array
    {
        return [
            'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA',
            'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM',
            'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA',
            'WV', 'WI', 'WY', 'AS', 'GU', 'MP', 'PR', 'VI',
        ];
    }

    /** @return list<string|Rule|Closure> */
    public static function phone(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:20',
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }
                if (! is_string($value) || self::digits($value) === null) {
                    $fail('Enter a valid US phone number (10 digits, e.g. (212) 555-0100).');
                }
            },
        ];
    }

    /** @return list<string|Rule|Closure> */
    public static function zip(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:10',
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }
                if (! is_string($value) || ! preg_match('/^\d{5}(-\d{4})?$/', trim($value))) {
                    $fail('Enter a valid US ZIP code (12345 or 12345-6789).');
                }
            },
        ];
    }

    /** @return list<string|Rule> */
    public static function state(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'size:2',
            Rule::in(self::stateCodes()),
        ];
    }

    /** @return list<string> */
    public static function dateOfBirth(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'date',
            'before:today',
            'after:1900-01-01',
        ];
    }

    public static function normalizePhone(?string $value): ?string
    {
        $digits = self::digits($value);
        if ($digits === null) {
            return $value ? trim($value) : null;
        }

        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
    }

    public static function normalizeZip(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($raw) === 5) {
            return $raw;
        }
        if (strlen($raw) === 9) {
            return substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return trim($value);
    }

    public static function normalizeState(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return strtoupper(trim($value));
    }

    private static function digits(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        // NANP: area + exchange cannot start with 0 or 1
        if ($digits[0] < '2' || $digits[3] < '2') {
            return null;
        }

        return $digits;
    }
}
