<?php

namespace App\Support\Pii;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Encryption\StringEncrypter as StringEncrypterContract;
use Illuminate\Support\Facades\Crypt;

/**
 * Laravel's encrypter without PHP serialisation, for the values Fortify encrypts itself (the two-factor secret and
 * recovery codes, which are plain strings). The stored ciphertext is then exactly what Crypt::encryptString makes, so
 * `pii:reencrypt` (key rotation) reads and rewrites those columns like every other registered column. Eloquent's own
 * `encrypted` casts already store unserialised strings, so installing this as Model::encryptUsing changes nothing for them.
 * It resolves the application encrypter on every call, so APP_PREVIOUS_KEYS keep working.
 */
final class StringEncrypter implements Encrypter, StringEncrypterContract
{
    public function encrypt($value, $serialize = false): string
    {
        return Crypt::encryptString((string) $value);
    }

    public function decrypt($payload, $unserialize = false): string
    {
        return Crypt::decryptString($payload);
    }

    public function encryptString(#[\SensitiveParameter] $value): string
    {
        return Crypt::encryptString($value);
    }

    public function decryptString($payload): string
    {
        return Crypt::decryptString($payload);
    }

    public function getKey(): string
    {
        return Crypt::getKey();
    }

    public function getAllKeys(): array
    {
        return Crypt::getAllKeys();
    }

    public function getPreviousKeys(): array
    {
        return Crypt::getPreviousKeys();
    }
}
