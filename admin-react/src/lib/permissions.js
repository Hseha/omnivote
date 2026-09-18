const ROLE_VIEWS = {
  admin: ['dashboard', 'candidates', 'voters', 'setup', 'results', 'settings', 'user_management'],
  teacher: ['dashboard', 'candidates', 'results'],
  ssg_president: ['ssg'],
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
