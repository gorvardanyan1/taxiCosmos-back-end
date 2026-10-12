import { t, tk } from '@/lib/i18n';

/**
 * Human labels for enum values and tab names (presentation only). Every value the server can send is
 * listed, so each label is a translation key; an unknown value falls back to its words, untranslated.
 */
const LABELS: Record<string, string> = {
    // Statuses
    active: tk('Active'), inactive: tk('Inactive'), suspended: tk('Suspended'), deactivated: tk('Deactivated'),
    pending_deletion: tk('Pending deletion'), pending: tk('Pending'), pending_review: tk('Pending review'),
    approved: tk('Approved'), rejected: tk('Rejected'), expired: tk('Expired'), review: tk('Under review'),
    requested: tk('Requested'), matched: tk('Matched'), arrived: tk('Arrived'), in_progress: tk('In progress'),
    completed: tk('Completed'), cancelled: tk('Cancelled'), no_driver_found: tk('No driver found'), en_route: tk('En route'),
    unpaid: tk('Unpaid'), authorized: tk('Authorized'), paid: tk('Paid'), payment_failed: tk('Payment failed'),
    partially_refunded: tk('Partially refunded'), refunded: tk('Refunded'), processing: tk('Processing'), failed: tk('Failed'),
    open: tk('Open'), resolved: tk('Resolved'), waiting: tk('Waiting'), ready: tk('Ready'), online: tk('Online'),
    offline: tk('Offline'), on_trip: tk('On trip'), in_trip: tk('In trip'), won: tk('Won'), lost: tk('Lost'),
    // People and roles
    rider: tk('Rider'), driver: tk('Driver'), admin: tk('Admin'), super_admin: tk('Super admin'), support: tk('Support'),
    finance: tk('Finance'), dispatcher: tk('Dispatcher'), staff: tk('Staff'), system: tk('System'), user: tk('User'),
    requester: tk('Requester'),
    // Documents
    license: tk("Driver's license"), id_card: tk('ID card'), vehicle_registration: tk('Vehicle registration'),
    insurance: tk('Insurance certificate'), background_check: tk('Background check'), driver_document: tk('Driver document'),
    document: tk('Document'),
    // Vehicle classes
    economy: tk('Economy'), comfort: tk('Comfort'), business: tk('Business'),
    // Money
    card: tk('Card'), cash: tk('Cash'), wallet: tk('Wallet'), stripe: tk('Stripe'), authorize_net: tk('Authorize.Net'),
    manual: tk('Manual'), charge: tk('Charge'), capture: tk('Capture'), refund: tk('Refund'), payout: tk('Payout'),
    bonus: tk('Bonus'), debit: tk('Debit'), trip_earning: tk('Trip earning'), cash_commission: tk('Cash commission due'),
    percentage: tk('Percentage'), fixed: tk('Fixed'), percentage_plus_fixed: tk('Percentage + fixed'),
    no_verified_bank_account: tk('No verified bank account'), negative_balance: tk('Negative balance'),
    duplicate: tk('Duplicate'), fraudulent: tk('Fraudulent'), product_not_received: tk('Not received'),
    // Support and ratings
    urgent: tk('Urgent'), high: tk('High'), normal: tk('Normal'), low: tk('Low'), safety: tk('Safety'), payment: tk('Payment'),
    trip: tk('Trip'), vehicle: tk('Vehicle'), fare_rule: tk('Fare rule'), commission_rule: tk('Commission rule'), flag: tk('Flag'), internal: tk('Internal'), zone: tk('Zone'), signup: tk('Sign-up'),
    cancel: tk('Cancel'), rider_to_driver: tk('Rider → Driver'), driver_to_rider: tk('Driver → Rider'),
    // Notification events
    chargeback_opened: tk('Chargeback opened'), document_expiring: tk('Document expiring'), manual_payment_pending: tk('Manual payment pending'),
    new_driver_application: tk('New driver application'), payout_batch_ready: tk('Payout batch ready'), stuck_trip: tk('Stuck trip'),
    system_health: tk('System health'), urgent_ticket: tk('Urgent ticket'),
    // Sign-in providers
    google: tk('Google'), apple: tk('Apple'), facebook: tk('Facebook'),
    // Tabs
    profile: tk('Profile'), documents: tk('Documents'), vehicles: tk('Vehicles'), earnings: tk('Earnings'),
    'trip-history': tk('Trip history'), 'bank-accounts': tk('Bank accounts'), trips: tk('Trips'), payments: tk('Payments'),
    'payment-methods': tk('Payment methods'), tickets: tk('Tickets'), ratings: tk('Ratings'), activity: tk('Activity'),
    security: tk('Security'), notifications: tk('Notifications'),
    // Account form fields
    current_password: tk('Current password'), password: tk('Password'), password_confirmation: tk('Confirm password'),
};

const words = (value: string): string => value.replace(/[_-]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

export function label(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    return LABELS[value] ? t(LABELS[value]) : words(value);
}
