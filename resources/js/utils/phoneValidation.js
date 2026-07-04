/**
 * Egyptian phone number validation utilities.
 * Supports Egyptian mobile numbers: 11 digits starting with 01.
 */

/** Strip everything that's not a digit */
export const digitsOnly = (val) => (val || '').replace(/\D/g, '');

/**
 * Enforce: only digits, max 11 chars.
 * Returns the cleaned value.
 */
export const enforcePhoneInput = (val) => digitsOnly(val).slice(0, 11);

/**
 * Returns an error message string or '' if valid.
 * - Empty string → no error (field is optional unless you check separately)
 * - Not starting with '01' → error
 * - Length != 11 → error
 */
export const validateEgyptianPhone = (val) => {
  if (!val || val.trim() === '') return '';
  const digits = digitsOnly(val);
  if (digits.length > 0 && digits.length < 11) return 'رقم الهاتف يجب أن يكون 11 رقمًا';
  if (digits.length === 11 && !digits.startsWith('01')) return 'يجب أن يبدأ رقم الهاتف بـ 01';
  return '';
};

/**
 * Returns true if the phone is fully valid (exactly 11 digits starting with 01).
 */
export const isValidPhone = (val) => {
  const digits = digitsOnly(val);
  return digits.length === 11 && digits.startsWith('01');
};
