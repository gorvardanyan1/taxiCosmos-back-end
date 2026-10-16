<?php

/*
 * Card payments (P6-T2). Every gateway call goes through App\Contracts\PaymentGatewayInterface; nothing else talks to a gateway.
 * Amounts are integers in minor units next to their currency.
 */
return [

    // The card gateway new cards are saved with (an open decision: Stripe or Authorize.Net at launch). Existing cards, holds and
    // charges always go back to the gateway that made them.
    'gateway' => env('PAYMENT_GATEWAY', 'stripe'),

    // `fake` answers like a gateway without any network (cards are test tokens such as tok_visa, see docs/payments.md). It is the
    // default in local, testing and e2e and refused anywhere else. `live` uses the real gateway classes (P6-T3 Stripe, P6-T4
    // Authorize.Net); until one exists, card payments answer 503 and the apps stay cash-only.
    'driver' => env('PAYMENT_DRIVER', in_array(env('APP_ENV'), ['local', 'testing', 'e2e'], true) ? 'fake' : 'live'),

    // A card trip holds the estimated fare plus this much (percent), so a slightly longer ride is still covered by the hold.
    'hold_buffer_percent' => (int) env('PAYMENT_HOLD_BUFFER_PERCENT', 20),

    // How long each gateway keeps a hold before it expires (hours), and how long before that a hold on a trip that is still
    // running is renewed by payments:refresh-holds.
    'hold_lifetime_hours' => [
        'stripe' => (int) env('STRIPE_HOLD_LIFETIME_HOURS', 168),
        'authorize_net' => (int) env('AUTHORIZE_NET_HOLD_LIFETIME_HOURS', 720),
    ],
    'hold_renew_margin_hours' => (int) env('PAYMENT_HOLD_RENEW_MARGIN_HOURS', 24),

    // How many live saved cards one rider may keep.
    'max_cards' => (int) env('PAYMENT_MAX_CARDS', 10),

    // Seconds one payment attempt may hold its idempotency lock (a gateway call plus the row update), and how long a second
    // attempt with the same key waits for it before answering "in progress".
    'lock_seconds' => (int) env('PAYMENT_LOCK_SECONDS', 60),
    'lock_wait_seconds' => (int) env('PAYMENT_LOCK_WAIT_SECONDS', 10),
];
