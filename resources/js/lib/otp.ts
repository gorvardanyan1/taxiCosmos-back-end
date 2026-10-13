/** Length of an authenticator-app code. */
export const OTP_LENGTH = 6;

/**
 * The digits of whatever was typed or pasted, at most six. Authenticator apps show a code as "123 456" and copy it that way, so
 * the digits are taken first and the length is cut afterwards; an input `maxLength` would cut the pasted text before the spaces
 * are removed and leave five digits.
 */
export const otpDigits = (value: string): string => value.replace(/\D/g, '').slice(0, OTP_LENGTH);
