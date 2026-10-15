import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/services/api_client.dart';

/// FutureProvider that fetches the elected officer roster. Mutated by the
/// SSG President screen on refresh; guarded by the backend's `officers.view`
/// permission + the `voting_closed` phase gate.
final presidentOfficersProvider = FutureProvider<List<Map<String, String>>>((ref) async {
  final dio = ref.read(apiClientProvider);
  final response = await dio.get('/ssg/officers');
  final data = response.data;

  final List<Map<String, String>> officers = [];
  if (data is Map) {
    final payload = data['data'];
    if (payload is List) {
      for (final item in payload) {
        if (item is Map) {
          officers.add({
            'position_label': item['position_label']?.toString() ?? 'Officer',
            'name': item['name']?.toString() ?? 'Unknown',
            'votes': item['votes']?.toString() ?? '0',
            'seat_count': item['seat_count']?.toString() ?? '1',
          });
        }
      }
    }
  }
  return officers;
});
