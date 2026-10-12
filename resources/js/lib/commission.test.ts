import { describe, expect, it } from 'vitest';
import vectors from '../../../tests/fixtures/commission-vectors.json';
import { commissionFor } from '@/lib/commission';

const amd = (amount: number) => ({ amount, currency: 'AMD' });

describe('commissionFor', () => {
    it('applies the percentage in integer maths, rounding half up', () => {
        expect(commissionFor(12500, { rate_bp: 1800, fixed_fee: null, minimum: null, maximum: null })).toBe(2250);
        expect(commissionFor(1003, { rate_bp: 1500, fixed_fee: null, minimum: null, maximum: null })).toBe(150); // 150.45 → 150
        expect(commissionFor(1010, { rate_bp: 1500, fixed_fee: null, minimum: null, maximum: null })).toBe(152); // 151.5 → 152
    });

    it('adds the fixed fee and clamps to min, max and the fare', () => {
        expect(commissionFor(10000, { rate_bp: 1600, fixed_fee: amd(300), minimum: null, maximum: null })).toBe(1900);
        expect(commissionFor(1000, { rate_bp: 1800, fixed_fee: null, minimum: amd(500), maximum: null })).toBe(500);
        expect(commissionFor(100000, { rate_bp: 1800, fixed_fee: null, minimum: null, maximum: amd(8000) })).toBe(8000);
        expect(commissionFor(2000, { rate_bp: null, fixed_fee: amd(2500), minimum: null, maximum: null })).toBe(2000);
        expect(commissionFor(0, { rate_bp: 1800, fixed_fee: null, minimum: amd(500), maximum: null })).toBe(0);
    });
});

describe('commissionFor matches the server calculator', () => {
    // The same vectors PHPUnit's CommissionCalculatorTest checks: the admin preview and the trip's stored commission cannot drift apart.
    it.each(vectors)('$name', (v) => {
        const rule = {
            rate_bp: v.type === 'fixed' ? null : v.rate_bps,
            fixed_fee: v.type === 'percentage' ? null : amd(v.fixed),
            minimum: v.min > 0 ? amd(v.min) : null,
            maximum: v.max === null ? null : amd(v.max),
        };

        expect(commissionFor(v.fare, rule)).toBe(v.commission);
    });
});
