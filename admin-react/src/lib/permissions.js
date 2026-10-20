const ROLE_VIEWS = {
  admin: ['dashboard', 'candidates', 'voters', 'user_management', 'departments', 'setup', 'results', 'records', 'announcements', 'audit', 'settings'],
  teacher: ['dashboard', 'candidates', 'results', 'announcements', 'settings'],
  // The President's own console: the certified-officer roster, the
  // announcements they author, and Settings (theme + profile + 2FA). The
  // announcements view is the shared one — it already talks to the
  // /ssg/console/ssg/announcements API surface.
  ssg_president: ['ssg', 'announcements', 'settings'],
};

const ROLE_DEFAULT_VIEW = {
  admin: 'dashboard',
  teacher: 'dashboard',
  ssg_president: 'ssg',
};

export function allowedViews(role) {
  return ROLE_VIEWS[role] ?? [];
}

export function defaultView(role) {
  return ROLE_DEFAULT_VIEW[role] ?? null;
}
