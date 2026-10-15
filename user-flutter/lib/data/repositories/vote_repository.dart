import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/election_status_model.dart';
import '../models/vote_receipt_model.dart';
import '../services/election_status_service.dart';
import '../services/secure_storage_service.dart';
import '../services/vote_service.dart';

final voteRepositoryProvider = Provider<VoteRepository>((ref) {
  return VoteRepository(
    ref.read(voteServiceProvider),
    ref.read(secureStorageServiceProvider),
    ref.read(electionStatusServiceProvider),
  );
});

class VoteRepository {
  final VoteService _voteService;
  final SecureStorageService _storage;
  final ElectionStatusService _electionStatusService;
  static const String _receiptKey = 'vote_receipt_token';

  VoteRepository(this._voteService, this._storage, this._electionStatusService);

  /// Storage key for a vote receipt, namespaced per account.
  ///
  /// Receipts used to share the single global [_receiptKey], so on a shared
  /// phone account B's My Ballot could fall back to account A's stored
  /// receipt (device isolation). New writes always carry the owner's id; a
  /// null [ownerId] resolves to the legacy global key for backward
  /// compatibility (see [getSavedReceipt]).
  static String _receiptKeyFor(String? ownerId) =>
      (ownerId == null || ownerId.isEmpty) ? _receiptKey : '$_receiptKey:$ownerId';

  /// Submits a ballot and returns the receipt token. The token is persisted
  /// in secure storage (it never reveals candidate choices).
  Future<VoteReceipt> submit({
    required Map<String, dynamic> selections,
    String? ownerId,
  }) async {
    final response = await _voteService.submitBallot(selections);
    final data = Map<String, dynamic>.from(response.data as Map);
    final receipt = VoteReceipt.fromJson(data);

    await saveReceipt(receipt.receiptToken, ownerId: ownerId);
    return receipt;
  }

  /// Reads the election phase to determine whether a timed-out submission was
  /// recorded before any retry option is shown.
  ///
  /// Returns the [ElectionStatus] or null when the status endpoint itself is
  /// unreachable (the caller then treats the submit as not recorded).
  Future<ElectionStatus?> checkStatus() async {
    final response = await _electionStatusService.getStatus();
    final data = response.data;
    if (data is Map) {
      return ElectionStatus.fromJson(Map<String, dynamic>.from(data));
    }
    return null;
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

  // --- Receipt persistence (per-account, local) ---
  /// Stores [token] under this account's own key so a device shared by two
  /// accounts can never show one voter's receipt to the other.
  Future<void> saveReceipt(String token, {String? ownerId}) async {
    await _storage.saveValue(_receiptKeyFor(ownerId), token);
  }

  /// Reads the current account's receipt.
  ///
  /// Receipts written before per-account scoping lived under the single
  /// global key: the first account to read after the upgrade claims it
  /// (claim-once migration — correct on the overwhelmingly common
  /// single-user device). The receipt token is non-identifying (it proves a
  /// ballot counted, never for whom), bounding the shared-device edge.
  Future<String?> getSavedReceipt({String? ownerId}) async {
    final key = _receiptKeyFor(ownerId);
    final scoped = await _storage.readValue(key);
    if (scoped != null && scoped.isNotEmpty) return scoped;
    if (ownerId == null || ownerId.isEmpty || key == _receiptKey) {
      return null;
    }
    final legacy = await _storage.readValue(_receiptKey);
    if (legacy == null || legacy.isEmpty) return null;
    await _storage.saveValue(key, legacy);
    await _storage.deleteValue(_receiptKey);
    return legacy;
  }
}
