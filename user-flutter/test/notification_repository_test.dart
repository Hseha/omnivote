import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/repositories/notification_repository.dart';
import 'package:omnivote/data/services/notification_service.dart';

class _FakeNotificationService extends NotificationService {
  _FakeNotificationService(this._payload) : super(Dio());

  final Object _payload;
  int? markedId;
  int markAllCalls = 0;

  @override
  Future<Response> getNotifications() async {
    return Response(
      data: _payload,
      statusCode: 200,
      requestOptions: RequestOptions(path: '/notifications'),
    );
  }

  @override
  Future<Response> markRead({int? id}) async {
    markedId = id;
    markAllCalls++;
    return Response(
      data: {'updated': 1},
      statusCode: 200,
      requestOptions: RequestOptions(path: '/notifications/read'),
    );
  }
}

void main() {
  test('parses the { notifications, unread } envelope', () async {
    final service = _FakeNotificationService({
      'notifications': [
        {
          'id': 1,
          'type': 'success',
          'title': 'Your vote was recorded',
          'body': 'Thanks for voting.',
          'link': '/ballot',
          'read': false,
          'created_at': '2026-10-01T10:00:00+00:00',
        },
        {
          'id': 2,
          'type': 'info',
          'title': 'Election results are in',
          'body': '',
          'link': null,
          'read': true,
          'created_at': '2026-09-30T09:00:00+00:00',
        },
      ],
      'unread': 1,
    });

    final feed = await NotificationRepository(service).getFeed();

    expect(feed.items, hasLength(2));
    expect(feed.items.first.title, 'Your vote was recorded');
    expect(feed.items.first.link, '/ballot');
    expect(feed.items.first.read, isFalse);
    expect(feed.items[1].link, isNull);
    expect(feed.items[1].read, isTrue);
    expect(feed.unread, 1);
  });

  test('falls back to counting unread when the envelope omits the total',
      () async {
    final service = _FakeNotificationService([
      {'id': 1, 'title': 'A', 'read': false},
      {'id': 2, 'title': 'B', 'read': true},
      {'id': 3, 'title': 'C', 'read': false},
    ]);

    final feed = await NotificationRepository(service).getFeed();

    expect(feed.items, hasLength(3));
    expect(feed.unread, 2);
  });

  test('tolerates a malformed payload without throwing', () async {
    final service = _FakeNotificationService('not-a-container');

    final feed = await NotificationRepository(service).getFeed();

    expect(feed.items, isEmpty);
    expect(feed.unread, 0);
  });

  test('markRead forwards a single id, or null for mark-all', () async {
    final service = _FakeNotificationService(const {'notifications': []});
    final repo = NotificationRepository(service);

    await repo.markRead(id: 42);
    expect(service.markedId, 42);

    await repo.markRead();
    expect(service.markedId, isNull);
    expect(service.markAllCalls, 2);
  });
}
