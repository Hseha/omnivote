import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/repositories/candidate_repository.dart';
import 'package:omnivote/data/services/candidate_service.dart';

/// Regression coverage for pagination: GET /api/candidates returns
/// `{ data, meta.total }` capped at 100 rows/page. The app must request more
/// than the default 50 and, for ballot ref-resolution, fetch every page.
class _FakeCandidateService extends CandidateService {
  _FakeCandidateService() : super(Dio());

  int calls = 0;
  int requestedPageSize = 0;

  @override
  Future<Response> getCandidates({
    String? positionId,
    String? tier,
    String? department,
    String? party,
    String? search,
    String? grade,
    int? perPage,
    int? page,
  }) async {
    calls++;
    requestedPageSize = perPage ?? 50;
    final pageN = page ?? 1;
    final size = perPage ?? 50;
    final start = (pageN - 1) * size;
    final all = [
      for (var i = 0; i < 150; i++)
        {
          'id': 'candidate-$i',
          'name': 'Candidate $i',
          'position': {
            'id': 'p1',
            'label': 'President',
            'tier': 'national',
            'seat_count': 1,
            'description': '',
          },
        },
    ];
    final end = (start + size) > all.length ? all.length : (start + size);
    return Response(
      data: {
        'data': all.sublist(start, end),
        'meta': {'total': all.length, 'current_page': pageN},
      },
      statusCode: 200,
      requestOptions: RequestOptions(path: '/candidates'),
    );
  }
}

void main() {
  test('getAllCandidates fetches every page until total is covered', () async {
    final service = _FakeCandidateService();
    final repo = CandidateRepository(service);

    final all = await repo.getAllCandidates();

    expect(service.requestedPageSize, 100);
    expect(service.calls, 2);
    expect(all, hasLength(150));
    expect(all.first.name, 'Candidate 0');
    expect(all.last.name, 'Candidate 149');
  });

  test('getCandidates defaults to the documented page size', () async {
    final service = _FakeCandidateService();
    final repo = CandidateRepository(service);

    final batch = await repo.getCandidates();

    expect(service.requestedPageSize, 50);
    expect(batch, hasLength(50));
  });
}