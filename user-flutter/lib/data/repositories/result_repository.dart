import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/election_result_model.dart';
import '../services/result_service.dart';

final resultRepositoryProvider = Provider<ResultRepository>((ref) {
  return ResultRepository(ref.read(resultServiceProvider));
});

class ResultRepository {
  final ResultService _resultService;

  ResultRepository(this._resultService);

  Future<List<ElectionResult>> getResults() async {
    final response = await _resultService.getResults();
    final data = response.data;
    final List<dynamic> raw;
    if (data is List) {
      raw = data;
    } else if (data is Map && data['results'] is List) {
      raw = data['results'] as List;
    } else {
      raw = const [];
    }
    return raw
        .map((r) => ElectionResult.fromJson(Map<String, dynamic>.from(r as Map)))
        .toList();
  }

  Future<bool> verify(String receiptToken) async {
    final response = await _resultService.verify(receiptToken);
    final data = response.data;
    if (data is Map) {
      return data['counted'] == true;
    }
    return false;
  }
}
