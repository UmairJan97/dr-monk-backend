<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Same as Laravel's `encrypted` cast, but never throws when APP_KEY
 * rotated or ciphertext is corrupt — list/show APIs must not 500.
 */
final class EncryptedSafe implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decrypt($value);
        } catch (DecryptException|Throwable) {
            try {
                return Crypt::decryptString($value);
            } catch (Throwable) {
                return null;
            }
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encrypt($value);
    }
}
