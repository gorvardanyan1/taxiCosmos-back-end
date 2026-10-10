/** Human labels for enum values (presentation only). */
const words = (value: string): string => value.replace(/[_-]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

const overrides: Record<string, string> = {
    authorize_net: 'Authorize.Net',
    id_card: 'ID card',
    background_check: 'Background check',
    vehicle_registration: 'Vehicle registration',
    license: "Driver's license",
    insurance: 'Insurance certificate',
    rider_to_driver: 'Rider → Driver',
    driver_to_rider: 'Driver → Rider',
    percentage_plus_fixed: 'Percentage + fixed',
    pending_deletion: 'Pending deletion',
    no_driver_found: 'No driver found',
    trip_earning: 'Trip earning',
    cash_commission: 'Cash commission due',
    no_verified_bank_account: 'No verified bank account',
    negative_balance: 'Negative balance',
    product_not_received: 'Not received',
};

export function label(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    return overrides[value] ?? words(value);
}
