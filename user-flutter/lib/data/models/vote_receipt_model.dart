/// Digital receipt returned after a ballot is submitted transactionally.
///
/// The receipt token itself is opaque and is used with `POST /api/results/verify`
/// to confirm a vote was counted — it never reveals candidate choices.
class VoteReceipt {
  final String receiptToken;

  const VoteReceipt({required this.receiptToken});

  factory VoteReceipt.fromJson(Map<String, dynamic> json) {
    return VoteReceipt(
      receiptToken: (json['receipt_token'] ?? json['receiptToken'] ?? json['receipt'] ?? '')
          .toString(),
    );
  }
}
