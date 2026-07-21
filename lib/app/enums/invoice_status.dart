import 'package:magic/magic.dart';

/// The settlement state of a billing invoice.
enum InvoiceStatus {
  paid,
  pending,
  failed;

  /// Localized label shown in the invoice status badge.
  String get label => switch (this) {
    InvoiceStatus.paid => trans('uptizm.enums.invoice_status.paid'),
    InvoiceStatus.pending => trans('uptizm.enums.invoice_status.pending'),
    InvoiceStatus.failed => trans('uptizm.enums.invoice_status.failed'),
  };
}
