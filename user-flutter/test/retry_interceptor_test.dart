import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:omnivote/data/services/retry_interceptor.dart';

/// Adapter whose per-call responses are scripted: an int is the HTTP status,
/// the string `'connection-error'` is a refused/reset connection.
class _ScriptedAdapter implements HttpClientAdapter {
  final List<Object> script;
  int calls = 0;

  _ScriptedAdapter(this.script);

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    expect(calls, lessThan(script.length),
        reason: 'the client made more attempts than the test scripted');
    final step = script[calls];
    calls++;
    // Gives the interceptor's "did this fail fast?" clock something to
    // measure, like a real round trip would.
    await Future<void>.delayed(const Duration(milliseconds: 5));
    if (step == 'connection-error') {
      throw DioException(
        requestOptions: options,
        type: DioExceptionType.connectionError,
        error: 'connection reset by peer',
      );
    }
    return ResponseBody.fromString(
      '{"ok":true}',
      step as int,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Dio _clientWith(
  List<Object> script, {
  Duration slowCutoff = const Duration(seconds: 3),
}) {
  final dio = Dio(BaseOptions(baseUrl: 'https://example.test/api'));
  dio.httpClientAdapter = _ScriptedAdapter(script);
  dio.interceptors.add(
    IdempotentRetryInterceptor(dio: dio, slowFailureCutoff: slowCutoff),
  );
  return dio;
}

_ScriptedAdapter _adapterOf(Dio dio) =>
    dio.httpClientAdapter as _ScriptedAdapter;

/// The retry policy exists so a flaky school Wi-Fi does not show a red error
/// state for a read request — and never so that a write gets replayed. These
/// tests pin both halves of that contract.
void main() {
  test('a GET that fails fast is retried once and then succeeds', () async {
    final dio = _clientWith(['connection-error', 200]);

    final response = await dio.get<dynamic>('/election/status');

    expect(response.statusCode, 200);
    expect(response.data, {'ok': true});
    expect(_adapterOf(dio).calls, 2);
  });

  test('a 502 gateway blip on a GET is retried', () async {
    final dio = _clientWith([502, 200]);

    final response = await dio.get<dynamic>('/positions');

    expect(response.statusCode, 200);
    expect(_adapterOf(dio).calls, 2);
  });

  test('a POST is never replayed automatically', () async {
    final dio = _clientWith([503, 200]);

    await expectLater(
      dio.post<dynamic>('/vote', data: const {'position': 'president'}),
      throwsA(isA<DioException>()),
    );
    expect(_adapterOf(dio).calls, 1,
        reason: 'ballot submission must never be sent twice');
  });

  test('a failure that took a full timeout to arrive is not retried',
      () async {
    // Failures slower than the cutoff are real outages, not blips; the client
    // must surface them at its normal latency instead of adding another one.
    final dio = _clientWith([502, 200], slowCutoff: Duration.zero);

    await expectLater(
      dio.get<dynamic>('/election/status'),
      throwsA(isA<DioException>()),
    );
    expect(_adapterOf(dio).calls, 1);
  });

  test('two failed attempts give up after the configured budget', () async {
    final dio = _clientWith([502, 502]);

    await expectLater(
      dio.get<dynamic>('/election/status'),
      throwsA(isA<DioException>()),
    );
    expect(_adapterOf(dio).calls, 2);
  });
}
