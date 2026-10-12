// Shared election-phase and account-role label helpers.
//
// These were previously copy-pasted across AdminDashboard, Header,
// ElectionSetup and Results (phase labels) and Header/Settings (role labels),
// which drifted over time. Single source of truth now.

/**
 * Human label for an election phase string from the API.
 *
 * A missing phase (null/undefined/'') renders as "Not Configured" rather than a
 * real-looking phase, so the badge never lies after an admin clears the
 * schedule.
 */
export function displayPhase(phase) {
  switch (phase) {
    case 'registration':
      return 'Registration';
    case 'registration_closed':
      return 'Registration Closed';
    case 'voting_open':
      return 'Voting Open';
    case 'voting_closed':
      return 'Voting Closed';
    default:
      return phase || 'Not Configured';
  }
}

/**
 * CSS class for a phase badge: green while voting is open, muted when no phase
 * is configured, blue otherwise.
 */
export function phaseClass(phase) {
  if (phase === 'voting_open') return 'green-phase';
  if (!phase) return 'muted-phase';
  return 'blue-phase';
}

/**
 * Human label for an account role.
 *
 * `fallback` is returned for a missing role so callers with different audiences
 * (the logged-in panel header vs. the full user list) keep their own wording.
 */
export function roleLabel(role, fallback = 'Panel Account') {
  switch (role) {
    case 'admin':
      return 'System Administrator';
    case 'teacher':
      return 'SSG Adviser';
    case 'ssg_president':
      return 'SSG President';
    case 'candidate':
      return 'Candidate';
    case 'student':
      return 'Student';
    default:
      return role || fallback;
  }
}
