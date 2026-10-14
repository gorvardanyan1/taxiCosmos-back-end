<?php

namespace App\Models;

use App\Casts\GeoPointCast;
use App\Data\GeoPoint;
use App\Enums\SavedPlaceLabel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address a rider keeps for quick selection in the rider app (home, work, other). The owner is set by the service from the
 * signed-in user, never from the request.
 *
 * @property GeoPoint $location
 */
#[Fillable(['label', 'name', 'address'])]
class SavedPlace extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'label' => SavedPlaceLabel::class,
            'location' => GeoPointCast::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
