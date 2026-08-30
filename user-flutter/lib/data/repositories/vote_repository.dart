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
    final response = await _voteService.submitVote(selections);
    final data = Map<String, dynamic>.from(response.data as Map);
    final receipt = VoteReceipt.fromJson(data);

    await saveReceipt(receipt.receiptToken);
    return receipt;
  }

  Future<Map<String, dynamic>?> getMyBallot() async {
    final response = await _voteService.getMyBallot();
    final data = response.data;
    if (data is Map) {
      return data.containsKey('selections')
          ? Map<String, dynamic>.from(data['selections'] as Map)
          : Map<String, dynamic>.from(data);
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
