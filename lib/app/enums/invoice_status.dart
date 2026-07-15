/// The settlement state of a billing invoice.
enum InvoiceStatus {
  paid,
  pending,
  failed;

  /// Human-readable label shown in the invoice status badge.
  String get label => switch (this) {
    InvoiceStatus.paid => 'Paid',
    InvoiceStatus.pending => 'Pending',
    InvoiceStatus.failed => 'Failed',
  };
}
