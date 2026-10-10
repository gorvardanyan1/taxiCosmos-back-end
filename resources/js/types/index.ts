/**
 * Shared prop and model types for the admin panel. Row shapes mirror what the
 * controllers send today (fixtures) and what the domain tasks' resources will send.
 */

export interface Money {
    /** Integer amount in the currency's minor units. */
    amount: number;
    currency: string;
}

export interface Currency {
    code: string;
    symbol: string;
    decimals: number;
    active?: boolean;
}

export interface AuthUser {
    id: number;
    name: string;
    email: string | null;
    role: string | null;
    roleLabel: string | null;
}

export interface NotificationItem {
    id: number;
    kind: 'safety' | 'document' | 'payment' | string;
    title: string;
    body: string;
    created_at: string;
    read: boolean;
}

export interface SharedProps {
    auth: { user: AuthUser | null; permissions: string[] };
    flash: { success: string | null; error: string | null };
    app: { name: string; locale: string; timezone: string; currencies: Currency[]; baseCurrency: string };
    navigation: { badges: Record<string, number>; notifications: NotificationItem[] } | null;
    errors: Record<string, string>;
    [key: string]: unknown;
}

/** Laravel LengthAwarePaginator serialised by Inertia. */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    path: string;
    links: { url: string | null; label: string; active: boolean }[];
}

/** Query-builder style list filters echoed back by the server (filter[<key>]). */
export type Filters = Record<string, string>;

/** Write endpoints a page may call; null until the owning domain task builds it. */
export type Actions<K extends string> = Record<K, string | null>;

export type UserStatus = 'active' | 'suspended' | 'deactivated' | 'pending_deletion';
export type DriverVerificationStatus = 'pending' | 'approved' | 'rejected' | 'expired';
export type VehicleClass = 'economy' | 'comfort' | 'business';
export type TripStatus = 'requested' | 'matched' | 'arrived' | 'in_progress' | 'completed' | 'cancelled' | 'no_driver_found';

export interface NamedOption {
    id: number;
    name: string;
}

export interface RiderRow {
    id: number;
    code: string;
    name: string;
    phone: string;
    email: string;
    status: UserStatus;
    trips_count: number;
    registered_at: string;
}

export interface DetailRecord {
    reference: string;
    description: string;
    status: string;
    value: Money | string;
    occurred_at: string;
}

export interface RiderDetail extends RiderRow {
    /** Unformatted values for the edit form (the row shows "—" for missing ones). */
    raw: { name: string | null; email: string | null; phone: string | null; locale: string | null };
    locale: string | null;
    suspension_reason: string | null;
    last_login_at: string | null;
    stats: { total_spent: Money; cancellation_rate_bp: number; open_tickets: number };
    deletion_scheduled_for: string | null;
    payment_methods: { id: number; brand: string; last4: string; expires: string; is_default: boolean }[];
    records: Record<'trips' | 'payments' | 'tickets' | 'ratings' | 'activity', DetailRecord[]>;
}

export interface DriverRow {
    id: number;
    code: string;
    name: string;
    phone: string;
    vehicle: { label: string; year: number };
    verification_status: DriverVerificationStatus;
    rating: string | null;
    trips_count: number;
    earnings: Money;
}

export interface DriverDocument {
    id: number;
    type: 'license' | 'id_card' | 'vehicle_registration' | 'insurance' | 'background_check';
    status: 'pending' | 'approved' | 'rejected' | 'expired';
    expires_at: string | null;
    rejection_reason: string | null;
    /** Absent on fixture data; real documents carry the stored (content-sniffed) type. */
    mime_type?: string;
    reviewed_at?: string | null;
    reviewer?: string | null;
    /** Short-lived signed link to the file, issued per page load. Absent on fixture data. */
    file_url?: string;
}

export interface Vehicle {
    id: number;
    make: string;
    model: string;
    year: number;
    color: string;
    plate_number: string;
    vehicle_class: VehicleClass;
    status: 'active' | 'inactive' | 'pending_review';
    is_primary: boolean;
}

export interface LedgerEntry {
    id: number;
    type: 'trip_earning' | 'cash_commission' | 'payout' | 'bonus' | 'adjustment';
    reference: string | null;
    amount: Money;
    occurred_at: string;
}

export interface DriverDetail extends Omit<DriverRow, 'vehicle'> {
    /** The primary vehicle; null when the driver has none yet. */
    vehicle: DriverRow['vehicle'] | null;
    documents: DriverDocument[];
    vehicles: Vehicle[];
    earnings_detail: {
        balance: Money;
        debt_limit_exceeded: boolean;
        summary: { week: Money; month: Money; all_time: Money };
        ledger: LedgerEntry[];
    };
    trip_history: { id: number; code: string; route: string; fare: Money; status: TripStatus; rating: string | null; occurred_at: string }[];
    bank_accounts: { id: number; bank_name: string; account_last4: string; is_default: boolean }[];
}

export interface TripRow {
    id: number;
    code: string;
    rider: string;
    driver: string | null;
    pickup_address: string;
    dropoff_address: string;
    status: TripStatus;
    fare: Money | null;
    requested_at: string;
}

export interface TicketRow {
    id: number;
    code: string;
    subject: string;
    priority: 'urgent' | 'high' | 'normal' | 'low';
    status: 'open' | 'in_progress' | 'waiting' | 'resolved';
    requester: { name: string; type: 'rider' | 'driver'; code: string };
    created_at: string;
}

export interface TicketDetail extends TicketRow {
    messages: { id: number; kind: 'requester' | 'staff' | 'internal'; body: string }[];
    linked_trip: { id: number; code: string } | null;
}

export interface RatingRow {
    id: number;
    trip_code: string;
    direction: 'rider_to_driver' | 'driver_to_rider';
    stars: number;
    comment: string;
    tags: string[];
    created_at: string;
}

export type Gateway = 'stripe' | 'authorize_net' | 'manual' | 'wallet';

export interface TransactionRow {
    id: number;
    code: string;
    trip_code: string;
    gateway: Gateway;
    type: 'capture' | 'charge' | 'debit' | 'refund';
    amount: Money;
    status: 'completed' | 'pending' | 'failed';
    created_at: string;
    gateway_reference: string | null;
    rider: string;
    payment_method: string;
    parent_code: string | null;
    failure_reason: string | null;
    metadata: Record<string, unknown>;
}

export interface PayoutRow {
    id: number;
    driver: string;
    bank_account: { bank_name: string; account_last4: string };
    period_start: string;
    period_end: string;
    amount: Money;
    status: 'pending' | 'approved' | 'paid' | 'failed' | 'rejected';
}

export interface ChargebackRow {
    id: number;
    gateway_dispute_id: string;
    transaction_code: string;
    rider: string;
    amount: Money;
    reason: string;
    status: 'review' | 'processing' | 'failed' | 'won' | 'lost';
    evidence_due_at: string;
}

export interface DriverBalanceRow {
    id: number;
    driver: string;
    balance: Money;
    ageing: { days_0_7: Money; days_8_30: Money; days_30_plus: Money };
    debt_limit_used_percent: number;
}

export type ReportMetric = 'revenue' | 'trips' | 'driver_earnings' | 'commission' | 'cancellations' | 'refunds' | 'payouts' | 'driver_debt';

export type ReportPoint = { label: string; trips: number; cancellations: number } & Record<
    'revenue' | 'driver_earnings' | 'commission' | 'refunds' | 'payouts' | 'driver_debt',
    Money
>;

export interface Zone {
    id: number;
    name: string;
    code: string;
    status: 'active' | 'inactive';
    polygon_valid: boolean;
    timezone: string;
    currency: string;
    /** Overlapping zones: the highest priority wins. */
    priority: number;
    /** Number of points in the polygon. */
    points: number;
}

export interface FareRule {
    zone_id: number;
    vehicle_class: VehicleClass;
    base_fare: Money;
    per_km: Money;
    per_minute: Money;
    minimum_fare: Money;
}

export interface SurgeRow {
    id: number;
    zone: string;
    multiplier: string;
    vehicle_class: VehicleClass | null;
    starts_at: string | null;
    ends_at: string | null;
    recurrence: string | null;
    reason: string;
    created_by: string | null;
}

export interface CommissionRule {
    id: number;
    zone: string;
    vehicle_class: VehicleClass;
    type: 'percentage' | 'percentage_plus_fixed' | 'fixed';
    rate_bp: number | null;
    fixed_fee: Money | null;
    minimum: Money | null;
    maximum: Money | null;
    applies_to_surge: boolean;
    effective_from: string;
}

export interface AdminUserRow {
    id: number;
    name: string;
    email: string;
    roles: string[];
    status: UserStatus;
    last_login_at: string | null;
}

export interface ActivityEntry {
    id: number;
    occurred_at: string;
    /** Copy stored with the entry; id is null for system and failed-sign-in entries. */
    actor: { id: number | null; name: string; role: string | null };
    action: string;
    target_type: string | null;
    target_id: number | null;
    target: string;
    reason: string | null;
    ip: string;
    user_agent: string | null;
    before: Record<string, unknown>;
    after: Record<string, unknown>;
}
