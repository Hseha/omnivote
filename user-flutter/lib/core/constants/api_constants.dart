class ApiConstants {
  // Use 10.0.2.2 for Android Emulator to access localhost
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api',
  );

  // Auth endpoints
  static const String login = '/auth/login';
  static const String register = '/auth/register';
  static const String logout = '/auth/logout';
  static const String me = '/auth/me';

  // Election endpoints
  static const String electionStatus = '/election/status';
  static const String registrationMe = '/registration/me';

  // Candidate endpoints
  static const String positions = '/positions';
  static const String candidates = '/candidates';

  // Candidacy application endpoint (registration phase only)
  static const String candidacyMe = '/candidacy/me';
  static const String candidacySubmit = '/candidate/apply';

  // Ballot endpoints
  static const String ballotMe = '/ballot/me';
  static const String ballotSubmit = '/ballot/me/submit';

  // Results endpoints
  static const String results = '/results';
  static const String verifyResult = '/results/verify';

  // Vote submission endpoint (voting_open phase only)
  static const String voteSubmit = '/vote';
}
