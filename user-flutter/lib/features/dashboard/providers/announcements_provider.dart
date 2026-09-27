import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/announcement_model.dart';
import '../../../data/repositories/result_repository.dart';

/// Published announcements, newest first. The system auto-creates an
/// "Official Election Results" announcement when the admin finalizes, so
/// results reach students here (and on the Results screen) without any manual
/// posting. Dashboard pull-to-refresh invalidates it to surface new posts.
final announcementsProvider = FutureProvider<List<Announcement>>((ref) async {
  return await ref.watch(resultRepositoryProvider).getAnnouncements();
});