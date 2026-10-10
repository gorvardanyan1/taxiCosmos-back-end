<?php

// Settings tabs not yet backed by storage (gateways/platform/maps: P13-T5; currencies: P6-T6).
// Secret values are never sent to the browser: only masked previews.
return [
    'gateways' => [
        ['key' => 'stripe', 'name' => 'Stripe', 'description' => 'Primary card processing gateway', 'connected' => true, 'masked_key' => 'sk_live_…a91F', 'mode' => 'live'],
        ['key' => 'authorize_net', 'name' => 'Authorize.Net', 'description' => 'Secondary processing gateway', 'connected' => true, 'masked_key' => '8x_prod_…27Bc', 'mode' => 'live'],
    ],
    'inactive_currencies' => [['code' => 'GEL', 'symbol' => '₾', 'decimals' => 2]],
    'platform' => [
        ['key' => 'required_documents', 'label' => 'Required driver documents', 'value' => 'Driver license, ID card, registration, insurance'],
        ['key' => 'payout_schedule', 'label' => 'Payout schedule', 'value' => 'Weekly · Monday · Minimum AMD 10,000'],
        ['key' => 'refund_second_approval', 'label' => 'Refund second approval', 'value' => 'AMD 50,000'],
        ['key' => 'driver_debt_limit', 'label' => 'Driver debt limit', 'value' => 'AMD 50,000'],
        ['key' => 'surge_cap', 'label' => 'Surge cap', 'value' => '×3.0'],
        ['key' => 'admin_session_timeout', 'label' => 'Admin session timeout', 'value' => '30 minutes'],
        ['key' => 'support_contact', 'label' => 'Support contact', 'value' => 'support@taxikosmos.am · +374 10 555 010'],
    ],
    'maps' => ['provider' => 'google', 'providers' => [['key' => 'google', 'name' => 'Google Maps'], ['key' => '2gis', 'name' => '2GIS']], 'masked_key' => 'AIza…39Fq'],
];
