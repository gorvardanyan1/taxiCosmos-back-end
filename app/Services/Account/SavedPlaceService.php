<?php

namespace App\Services\Account;

use App\Data\GeoPoint;
use App\Enums\SavedPlaceLabel;
use App\Exceptions\SavedPlaceException;
use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A rider's saved places. The cap is checked under a lock on the user's row, so two requests sent together cannot both
 * take the last slot.
 */
final class SavedPlaceService
{
    public function create(User $user, SavedPlaceLabel $label, string $name, string $address, GeoPoint $location): SavedPlace
    {
        return DB::transaction(function () use ($user, $label, $name, $address, $location) {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $max = (int) config('taxikosmos.saved_places.max');
            SavedPlace::query()->where('user_id', $user->getKey())->count() < $max || throw SavedPlaceException::limitReached($max);

            $place = (new SavedPlace)->fill(['label' => $label, 'name' => $name, 'address' => $address]);
            $place->location = $location;
            $place->user()->associate($user);
            $place->save();

            return $place;
        });
    }

    /**
     * @param  array{label?: SavedPlaceLabel, name?: string, address?: string}  $attributes
     */
    public function update(SavedPlace $place, array $attributes, ?GeoPoint $location): SavedPlace
    {
        $place->fill($attributes);

        if ($location !== null) {
            $place->location = $location;
        }

        $place->save();

        return $place;
    }

    public function delete(SavedPlace $place): void
    {
        $place->delete();
    }
}
