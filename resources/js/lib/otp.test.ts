import { describe, expect, it } from 'vitest';
import { otpDigits } from '@/lib/otp';

describe('otpDigits', () => {
    it.each([
        ['123456', '123456'],
        ['123 456', '123456'],
        [' 123456 ', '123456'],
        ['123-456', '123456'],
        ['12ab-34 56', '123456'],
        ['1234567890', '123456'],
        ['12345', '12345'],
        ['', ''],
        ['abc', ''],
    ])('%j becomes %j', (typed, expected) => {
        expect(otpDigits(typed)).toBe(expected);
    });
});
