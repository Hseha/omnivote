// Shared date/time formatting helpers.

/**
 * The 24-hour "HH:MM:SS TZ" clock string rendered in the admin header and
 * dashboard. Falls back to the literal "LOCAL" when the runtime cannot resolve
 * a timezone name.
 *
 * @param {Date} [date] Instant to format; defaults to now.
 * @returns {string}
 */
export function formatClock(date = new Date()) {
  const tzLabel = new Intl.DateTimeFormat('en-US', {
    timeZoneName: 'short',
  })
    .formatToParts(date)
    .find((part) => part.type === 'timeZoneName')?.value;

  return (
    date.toLocaleTimeString('en-US', {
      hour12: false,
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    }) +
    ' ' +
    (tzLabel || 'LOCAL')
  );
}
