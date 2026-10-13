<?php

namespace App\Http\Requests\Api\V1;

/**
 * The search-session token the app makes up (a UUID is typical) and sends with every keystroke's autocomplete call and the
 * final place-details call, so Google bills the whole search once. Letters, digits, - and _ only: it goes into a provider request.
 */
final class GeoSessionToken
{
    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'];
    }
}
