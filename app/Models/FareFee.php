<?php

namespace App\Models;

use App\Enums\FareFeeAppliesTo;
use Carbon\CarbonInterface;
use Database\Factories\FareFeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A fixed surcharge by place (an airport pickup, a toll): for a whole zone (zone_id) or for a drawn
 * area (polygon, written with SQL; never an attribute). Effective-dated like fare rules.
 */
class FareFee extends Model
{
    /** @use HasFactory<FareFeeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['polygon'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'applies_to' => FareFeeAppliesTo::class,
            'amount' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    /**
     * @param  Builder<FareFee>  $query
     */
    public function scopeEffectiveAt(Builder $query, CarbonInterface $at): void
    {
        $query->where('effective_from', '<=', $at)
            ->where(fn (Builder $open) => $open->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }
}
