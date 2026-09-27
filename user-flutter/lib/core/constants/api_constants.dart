class ApiConstants {
  // Production default targets the Tailscale server over HTTPS; override with
  // --dart-define=API_BASE_URL=https://... for local dev on an emulator
  // (e.g. http://10.0.2.2:8000/api on the Android emulator).
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://debian.tail7e9e1e.ts.net/api',
  );

  // Auth endpoints
  static const String login = '/auth/login';
  static const String logout = '/auth/logout';
  static const String me = '/auth/me';
  static const String changePassword = '/auth/password/change';

  // Self-service recovery (assessment M-3).
  //
  // Students have no mailbox — their handle is a name-derived slug — so an
  // emailed reset link has nowhere to go. This redeems the registrar-issued
  // activation code instead, which is what lets a locked-out voter recover
  // without an administrator touching the account.
  static const String resetPasswordWithCode = '/auth/password/reset-with-code';

  // Election endpoints
  static const String electionStatus = '/election/status';
  static const String registrationMe = '/registration/me';

  // Candidate endpoints
  static const String positions = '/positions';
  static const String candidates = '/candidates';
  static const String departments = '/departments';
  static const String parties = '/parties';

  // Candidacy application endpoint (registration phase only)
  static const String candidacyMe = '/candidacy/me';
  static const String candidacySubmit = '/candidate/apply';

  // Ballot endpoints
  static const String ballotMe = '/ballot/me';
  static const String ballotSubmit = '/ballot/me/submit';

  // Results endpoints
  static const String results = '/results';
  static const String verifyResult = '/results/verify';

  // Announcements (public)
  static const String announcements = '/announcements';

  // Public branding (site name, colors, logo) — safe before/without auth
  static const String branding = '/branding';

  // Vote submission endpoint (voting_open phase only)
  static const String voteSubmit = '/vote';
}
