import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/constants/api_constants.dart';
import 'api_client.dart';

final candidacyServiceProvider = Provider<CandidacyService>((ref) {
  return CandidacyService(ref.read(apiClientProvider));
});

class CandidacyService {
  final Dio _dio;

  CandidacyService(this._dio);

  /// Submits a candidacy application as multipart/form-data.
  ///
  /// [photoBytes] is optional but must be provided with a non-empty [photoName]
  /// (and a supported mime type) when present.
  Future<Response> submit({
    required String positionId,
    String? slogan,
    required String platformStatement,
    String? partyName,
    List<int>? photoBytes,
    String? photoName,
  }) async {
    final form = _buildForm(
      positionId: positionId,
      slogan: slogan,
      platformStatement: platformStatement,
      partyName: partyName,
      photoBytes: photoBytes,
      photoName: photoName,
    );

    return await _dio.post(
      ApiConstants.candidacySubmit,
      data: form,
      options: Options(contentType: 'multipart/form-data'),
    );
  }

  /// Edits the caller's campaign (PUT, multipart). The position stays locked
  /// server-side once the application is approved; clients send it anyway.
  Future<Response> update({
    required String positionId,
    String? slogan,
    required String platformStatement,
    String? partyName,
    List<int>? photoBytes,
    String? photoName,
  }) async {
    final form = _buildForm(
      positionId: positionId,
      slogan: slogan,
      platformStatement: platformStatement,
      partyName: partyName,
      photoBytes: photoBytes,
      photoName: photoName,
    );

    return await _dio.put(
      ApiConstants.candidacyUpdate,
      data: form,
      options: Options(contentType: 'multipart/form-data'),
    );
  }

  /// Withdraws/forfeits the caller's pending or approved candidacy.
  Future<Response> withdraw() async {
    return await _dio.post(ApiConstants.candidacyWithdraw);
  }

  FormData _buildForm({
    required String positionId,
    String? slogan,
    required String platformStatement,
    String? partyName,
    List<int>? photoBytes,
    String? photoName,
  }) {
    return FormData.fromMap({
      'position_id': positionId,
      if (slogan != null && slogan.trim().isNotEmpty) 'slogan': slogan,
      'platform_statement': platformStatement,
      if (partyName != null && partyName.trim().isNotEmpty) 'party_name': partyName,
      'certify': true,
      if (photoBytes != null && photoName != null)
        'photo': MultipartFile.fromBytes(
          photoBytes,
          filename: photoName,
        ),
    });
  }

  /// Fetches the current user's application status.
  Future<Response> getMyApplication() async {
    return await _dio.get(ApiConstants.candidacyMe);
  }
}
