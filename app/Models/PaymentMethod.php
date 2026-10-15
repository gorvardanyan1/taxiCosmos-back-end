<?php

namespace App\Models;

use App\Enums\PaymentGateway;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A rider's saved card as the gateway knows it (P6-T1): the gateway's tokens plus brand, last four digits and expiry. Card numbers and
 * CVCs never reach the platform (they go to Stripe Elements / Authorize.Net Accept.js inside the apps), so this table cannot hold them.
 * The gateway ids are internal and never sent to the apps.
 */
#[Hidden(['gateway_customer_id', 'gateway_payment_method_id'])]
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = ['*'];

    protected $attributes = ['is_default' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway' => PaymentGateway::class,
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
