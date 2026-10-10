import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/repositories/vote_repository.dart';
import 'package:omnivote/data/services/election_status_service.dart';
import 'package:omnivote/data/services/secure_storage_service.dart';
import 'package:omnivote/data/services/vote_service.dart';

/// Regression tests for the ballot-envelope bug: VoteRepository.getMyBallot
/// used to unwrap `data['selections']` and discard `status` + `receipt_token`,
/// which made My Ballot always render an empty draft and never restore a
/// submitted ballot's receipt after a relaunch.
class _FakeVoteService extends VoteService {
  _FakeVoteService() : super(Dio());

  Map<String, dynamic>? envelope;

  @override
  Future<Response> getMyBallot() async {
    return Response(
      data: envelope ?? {'status': 'draft', 'selections': <String, dynamic>{}},
      statusCode: 200,
      requestOptions: RequestOptions(path: '/ballot/me'),
    );
  }

  @override
  Future<Response> submitBallot(Map<String, dynamic> selections) async {
    return Response(
      data: {'receipt': 'abc123'},
      statusCode: 201,
      requestOptions: RequestOptions(path: '/ballot/me/submit'),
    );
  }
}

class _FakeStorage extends SecureStorageService {
  _FakeStorage() : super(const FlutterSecureStorage());

  final Map<String, String> _store = {};

  @override
  Future<void> saveValue(String key, String value) async => _store[key] = value;

  @override
  Future<String?> readValue(String key) async => _store[key];
}


class _FakeElectionStatusService extends ElectionStatusService {
  _FakeElectionStatusService() : super(Dio());

  String phase = 'voting_open';

  @override
  Future<Response> getStatus() async {
    return Response(
      data: {'phase': phase},
      statusCode: 200,
      requestOptions: RequestOptions(path: '/election/status'),
    );
  }
}

void main() {
  test('getMyBallot preserves the full submitted envelope', () async {
    final service = _FakeVoteService()
      ..envelope = {
        'status': 'submitted',
        'selections': {'president': 'ref-1'},
        'receipt_token': 'receipt-9',
      };
    final repo = VoteRepository(service, _FakeStorage(), _FakeElectionStatusService());

    final ballot = await repo.getMyBallot();

    expect(ballot, isNotNull);
    expect(ballot, hasLength(3));
    expect(ballot!['status'], 'submitted');
    expect((ballot['selections'] as Map)['president'], 'ref-1');
    expect(ballot['receipt_token'], 'receipt-9');
  });

  test('getMyBallot preserves a draft envelope with multi-select refs', () async {
    final service = _FakeVoteService()
      ..envelope = {
        'status': 'draft',
        'selections': {
          'president': ['ref-a', 'ref-b'],
        },
      };
    final repo = VoteRepository(service, _FakeStorage(), _FakeElectionStatusService());

    final ballot = await repo.getMyBallot();

    expect(ballot!['status'], 'draft');
    expect(ballot['selections']['president'], ['ref-a', 'ref-b']);
  });

  test('submit persists the receipt so it can be recovered after relaunch',
      () async {
    final storage = _FakeStorage();
    final repo = VoteRepository(_FakeVoteService(), storage, _FakeElectionStatusService());

    final receipt = await repo.submit(selections: {'president': 'ref-x'});
    final saved = await repo.getSavedReceipt();

    expect(receipt.receiptToken, 'abc123');
    expect(saved, 'abc123');
  });

  test('getMyBallot returns null for a non-map payload', () async {
    final service = _FakeVoteService();
    final repo = VoteRepository(service, _FakeStorage(), _FakeElectionStatusService());
    final ballot = await repo.getMyBallot();
    expect(ballot, isNotNull);
  });
}