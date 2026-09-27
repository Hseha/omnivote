/*
 * Network-free avatar fallback. The profile markers fall back to DiceBear
 * (an external service) when the account has no custom avatar; offline that
 * <img> would render as a broken image. This helper renders the initials as an
 * inline data-URI SVG instead, so the panel keeps a useful avatar with zero
 * network requests.
 */
export function initialsAvatarDataUri(name = '', size = 64, radius = 12) {
  const initials =
    (name || 'U')
      .split(/\s+/)
      .filter(Boolean)
      .map((word) => word[0])
      .slice(0, 2)
      .join('')
      .toUpperCase() || 'U';

  const svg =
    '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '">' +
    '<rect width="' + size + '" height="' + size + '" rx="' + radius + '" fill="#2563eb"/>' +
    '<text x="50%" y="50%" dy="0.35em" font-family="-apple-system, Segoe UI, Roboto, Arial, sans-serif" ' +
    'font-size="' + Math.round(size * 0.38) + '" font-weight="600" fill="#ffffff" ' +
    'text-anchor="middle">' + initials + '</text></svg>';

  return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
}

/*
 * Default "no photo" user icon, as an inline data-URI SVG. Used wherever a
 * person has no avatar of their own: it needs no network request (so it can
 * never render as a broken image) and matches the student app's
 * CachedAvatar person fallback, keeping both clients visually consistent.
 */
export function defaultUserIconDataUri(size = 76, { background = '#dbeafe', glyph = '#2563eb' } = {}) {
  const svg =
    '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 24 24">' +
    '<rect width="24" height="24" rx="24" fill="' + background + '"/>' +
    '<g transform="translate(3.6 3.2) scale(0.7)" fill="' + glyph + '">' +
    '<path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4z"/>' +
    '<path d="M12 14c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>' +
    '</g></svg>';

  return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
}

/*
 * Swap a broken avatar <img> for the offline-safe initials data URI. Setting
 * onerror to null first guarantees a DiceBear failure can never loop.
 */
export function fallbackAvatarOnError(name = '') {
  return (e) => {
    e.currentTarget.onerror = null;
    e.currentTarget.src = initialsAvatarDataUri(name);
  };
}