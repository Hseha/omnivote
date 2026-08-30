import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/constants/api_constants.dart';
import '../../core/providers/auth_event_provider.dart';
import 'secure_storage_service.dart';

/// A short-lived in-memory token store used on web so that Bearer tokens are
/// never persisted to localStorage/shared_preferences. On native platforms the
/// token is held in the platform secure storage instead (see
/// [SecureStorageService]).
class TokenStore {
  String? _memoryToken;
  final SecureStorageService? _secureStorage;

  TokenStore(this._secureStorage);

  bool get _useMemory => kIsWeb;

  Future<void> save(String token) async {
    if (_useMemory) {
      _memoryToken = token;
    } else {
      await _secureStorage?.saveToken(token);
    }
  }

  Future<String?> read() async {
    if (_useMemory) {
      return _memoryToken;
    }
    return _secureStorage?.getToken();
  }

  Future<void> delete() async {
    if (_useMemory) {
      _memoryToken = null;
    } else {
      await _secureStorage?.deleteToken();
    }
  }
}

final Provider<TokenStore> tokenStoreProvider = Provider<TokenStore>((ref) {
  return TokenStore(ref.read(secureStorageServiceProvider));
});

final Provider<Dio> apiClientProvider = Provider<Dio>((ref) {
  final dio = Dio(BaseOptions(
    baseUrl: ApiConstants.baseUrl,
    connectTimeout: const Duration(seconds: 10),
    receiveTimeout: const Duration(seconds: 10),
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
  ));

  dio.interceptors.add(InterceptorsWrapper(
    onRequest: (options, handler) async {
      final tokenStore = ref.read(tokenStoreProvider);
      final token = await tokenStore.read();
      if (token != null && token.isNotEmpty) {
        options.headers['Authorization'] = 'Bearer $token';
      }
      return handler.next(options);
    },
    onError: (DioException e, handler) {
      // Do not force-logout on 401s originating from the login/register
      // request itself (invalid credentials must not clear an existing user).
      final path = e.requestOptions.path;
      final isLoginOrRegister = path.contains('/auth/login') || path.contains('/auth/register');

      if (e.response?.statusCode == 401 && !isLoginOrRegister) {
        // Signal unauthorized event to break circular dependency
        ref.read(authEventProvider.notifier).state = AuthEvent.unauthorized;
      }
      return handler.next(e);
    },
  ));

  return dio;
});

class ApiValidationException implements Exception {
  final String message;
  final Map<String, dynamic>? errors;

  ApiValidationException(this.message, this.errors);

  @override
  String toString() => 'ApiValidationException: $message';
}
