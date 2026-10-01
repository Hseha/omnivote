/*
 * Client-side mirror of the backend password policy.
 *
 * The backend is authoritative — Laravel's `Password::defaults()` (min 8
 * characters, at least one letter, at least one number) is what actually
 * decides whether a password is accepted. This helper exists purely so the user
 * finds out while typing instead of after submitting, and it must be kept in
 * sync if `Password::defaults()` is ever tightened.
 *
 * Note there is deliberately no special-character requirement: `Password::defaults()`
 * does not require one, so enforcing it here would reject passwords the backend
 * is willing to accept.
 */
export function passwordProblem(value) {
  if (value.length < 8) return 'Use at least 8 characters.';
  if (!/[A-Za-z]/.test(value)) return 'Include at least one letter.';
  if (!/[0-9]/.test(value)) return 'Include at least one number.';
  return null;
}