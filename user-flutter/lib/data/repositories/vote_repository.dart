import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/vote_receipt_model.dart';
import '../services/secure_storage_service.dart';
import '../services/vote_service.dart';

final voteRepositoryProvider = Provider<VoteRepository>((ref) {
  return VoteRepository(
    ref.read(voteServiceProvider),
    ref.read(secureStorageServiceProvider),
  );
});

class VoteRepository {
  final VoteService _voteService;
  final SecureStorageService _storage;
  static const String _receiptKey = 'vote_receipt_token';

  VoteRepository(this._voteService, this._storage);

  /// Submits a ballot and returns the receipt token. The token is persisted
  /// in secure storage (it never reveals candidate choices).
  Future<VoteReceipt> submit({required Map<String, dynamic> selections}) async {
    final response = await _voteService.submitBallot(selections);
    final data = Map<String, dynamic>.from(response.data as Map);
    final receipt = VoteReceipt.fromJson(data);

    await saveReceipt(receipt.receiptToken);
    return receipt;
  }

  /// Persists the in-progress selections for the current user (GET/PUT /ballot/me).
  Future<void> saveDraft(Map<String, dynamic> selections) async {
    await _voteService.saveDraft(selections);
  }

  /// Returns the full `{ status, selections, receipt_token }` envelope from
  /// GET /ballot/me. Previously this unwrapped `selections` and dropped the
  /// envelope, which made My Ballot always render an empty draft and hid the
  /// receipt token even after the ballot had been submitted.
  ///
  /// After submission the server returns empty `selections` and a null
  /// `receipt_token` by design: keeping either next to the user id would let a
  /// database dump re-attach the ballot to the voter. Callers must fall back
  /// to [readSavedReceipt] (the copy stored on this device at submit time).
  Future<Map<String, dynamic>?> getMyBallot() async {
    final response = await _voteService.getMyBallot();
    final data = response.data;
    if (data is Map) {
      final envelope = Map<String, dynamic>.from(data);
      if (envelope['selections'] is Map) {
        envelope['selections'] =
            Map<String, dynamic>.from(envelope['selections'] as Map);
      }
      return envelope;
    }
    return null;
  }

  /// Verifies a receipt token with the server. Returns `true` if counted.
  Future<bool> verify(String receiptToken) async {
    final response = await _voteService.verifyReceipt(receiptToken);
    final data = response.data;
    if (data is Map) {
      return data['counted'] == true;
    }
    return false;
  }

  // --- Receipt persistence (non-identifying, local) ---
  Future<void> saveReceipt(String token) async {
    await _storage.saveValue(_receiptKey, token);
  }

  Future<String?> getSavedReceipt() async {
    return await _storage.readValue(_receiptKey);
  }
}
