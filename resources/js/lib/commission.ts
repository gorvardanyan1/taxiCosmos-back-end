import type { CommissionRule } from '@/types';

/**
 * Commission for a fare in minor units, using integer maths only:
 * rate (basis points, rounded half up) + fixed fee, clamped to the rule's min/max and to the fare.
 */
export function commissionFor(fareMinor: number, rule: Pick<CommissionRule, 'rate_bp' | 'fixed_fee' | 'minimum' | 'maximum'>): number {
    const percentage = rule.rate_bp === null ? 0 : Math.floor((fareMinor * rule.rate_bp + 5000) / 10000);
    let commission = percentage + (rule.fixed_fee?.amount ?? 0);
    if (rule.minimum) commission = Math.max(commission, rule.minimum.amount);
    if (rule.maximum) commission = Math.min(commission, rule.maximum.amount);
    return Math.min(commission, fareMinor);
}
