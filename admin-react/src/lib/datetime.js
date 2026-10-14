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

/**
 * Formats a timestamp (Laravel ISO-8601 string, epoch, or Date) as the
 * "YYYY-MM-DD HH:MM:SS" string used across admin tables (audit log, etc.).
 * Rendered in the viewer's local timezone. Returns "—" for missing values and
 * the raw input unchanged when it cannot be parsed.
 *
 * @param {string|number|Date} [value]
 * @returns {string}
 */
export function formatDateTime(value) {
  if (value === null || value === undefined || value === '') return '—';
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  const pad = (n) => String(n).padStart(2, '0');
  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
    `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
  );
}
