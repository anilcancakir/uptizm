import 'package:flutter/foundation.dart';

import '../enums/invoice_status.dart' show InvoiceStatus;
import '../enums/team_role.dart' show TeamRole;

/// A person with access to the current team.
///
/// Mirrors the `Member` interface in the React teams mock.
@immutable
class TeamMember {
  /// Stable identifier used for the active-on-call and self checks.
  final String id;

  /// Full display name.
  final String name;

  /// Account email shown under the name.
  final String email;

  /// Two-letter avatar initials.
  final String initials;

  /// Access level within the team.
  final TeamRole role;

  /// Relative join date string, e.g. `"3 months ago"`.
  final String joinedAt;

  /// Whether this row is the signed-in user (drives the "Remove" hide rule).
  final bool isSelf;

  const TeamMember({
    required this.id,
    required this.name,
    required this.email,
    required this.initials,
    required this.role,
    required this.joinedAt,
    required this.isSelf,
  });
}

/// A teammate invited but not yet joined.
///
/// Mirrors the `Invite` interface in the React teams mock.
@immutable
class TeamInvitation {
  /// Stable identifier used for the revoke action.
  final String id;

  /// The invited email address.
  final String email;

  /// The role the invite will grant once accepted.
  final TeamRole role;

  /// Relative time string of when the invite was sent, e.g. `"2 days ago"`.
  final String invitedAt;

  const TeamInvitation({
    required this.id,
    required this.email,
    required this.role,
    required this.invitedAt,
  });
}

/// A block of rotation time a member is the responder.
///
/// Denormalized from [TeamMember] (id/name/initials copied in) so the
/// on-call schedule view does not need to join against `teamMembers` to
/// render a row. Mirrors the `OnCallShift` interface in the React oncall
/// mock, flattened onto the member fields the view actually renders.
@immutable
class OnCallShift {
  /// The responder's [TeamMember.id].
  final String memberId;

  /// The responder's display name.
  final String memberName;

  /// The responder's avatar initials.
  final String initials;

  /// Span label for the rotation list, e.g. `"Mon 09:00 - Wed 09:00"`.
  final String span;

  /// True for the shift covering "now".
  final bool current;

  const OnCallShift({
    required this.memberId,
    required this.memberName,
    required this.initials,
    required this.span,
    required this.current,
  });
}

/// One row in the team's billing history.
///
/// Mirrors the `Invoice` interface in the React billing mock, plus
/// [number]/[status] (not carried by the React source, which formats the
/// description client-side) so the badge and header have typed data to render.
@immutable
class Invoice {
  /// Stable identifier, e.g. `'inv-2026-06'`.
  final String id;

  /// Human-readable invoice number shown in the receipt link's context.
  final String number;

  /// Billing date, e.g. `"Jun 1, 2026"`.
  final String date;

  /// Formatted amount, e.g. `"$348.00"`.
  final String amount;

  /// Settlement state.
  final InvoiceStatus status;

  const Invoice({
    required this.id,
    required this.number,
    required this.date,
    required this.amount,
    required this.status,
  });

  /// Decodes an [Invoice] from a `GET /billing/invoices` `data[]` entry (see
  /// `backend/app/Http/Resources/InvoiceResource.php`).
  ///
  /// [amount] is rendered server-side (Cashier's `Invoice::total()` returns
  /// the formatted, currency-aware string), so no client-side money math is
  /// involved; [date] is reformatted from the ISO 8601 wire value into the
  /// screen's existing `"Jun 1, 2026"` display shape.
  factory Invoice.fromMap(Map<String, dynamic> map) {
    return Invoice(
      id: (map['id'] as String?) ?? '',
      number: (map['number'] as String?) ?? '',
      date: _formatInvoiceDate(map['date'] as String?),
      amount: (map['amount'] as String?) ?? '',
      status: _invoiceStatusFromWire(map['status'] as String?),
    );
  }
}

/// Decodes a Cashier/Stripe invoice `status` wire string into an
/// [InvoiceStatus].
///
/// Stripe's raw invoice statuses (`draft`, `open`, `paid`, `uncollectible`,
/// `void`) do not line up 1:1 with this screen's 3-state vocabulary; `open`/
/// `draft` map to [InvoiceStatus.pending] (still awaiting settlement) and
/// `uncollectible`/`void` map to [InvoiceStatus.failed] (never settled). An
/// absent/unrecognized value falls back to [InvoiceStatus.pending] rather
/// than silently defaulting to `paid`.
InvoiceStatus _invoiceStatusFromWire(String? wire) {
  return switch (wire) {
    'paid' => InvoiceStatus.paid,
    'open' => InvoiceStatus.pending,
    'draft' => InvoiceStatus.pending,
    'uncollectible' => InvoiceStatus.failed,
    'void' => InvoiceStatus.failed,
    _ => InvoiceStatus.pending,
  };
}

/// The short month names [_formatInvoiceDate] indexes by `DateTime.month`.
const List<String> _monthAbbreviations = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

/// Formats an ISO 8601 [iso] timestamp as `"Jun 1, 2026"`, matching the
/// design-lab fixture's display shape. Returns an empty string when [iso] is
/// absent or unparsable (never throws out of a decode path).
String _formatInvoiceDate(String? iso) {
  if (iso == null) return '';
  final DateTime? date = DateTime.tryParse(iso);
  if (date == null) return '';
  return '${_monthAbbreviations[date.month - 1]} ${date.day}, ${date.year}';
}

/// Like [_formatInvoiceDate], but returns `null` (rather than an empty
/// string) on an absent/unparsable [iso], so a caller can distinguish "no
/// date" from a formatted one with a plain `!= null` check.
String? _formatOptionalDate(String? iso) {
  if (iso == null) return null;
  final DateTime? date = DateTime.tryParse(iso);
  if (date == null) return null;
  return '${_monthAbbreviations[date.month - 1]} ${date.day}, ${date.year}';
}

/// A saved payment method shown on the billing page.
///
/// Every field is nullable: `GET /billing/payment-method` is the one
/// Stripe-live billing read and soft-fails server-side to an all-null
/// payload on a Stripe outage (see `BillingController::paymentMethod()`), so
/// this value object must represent "no card on file" without throwing.
@immutable
class PaymentMethod {
  /// Card network/brand, e.g. `"Visa"`; `null` when no card is on file.
  final String? brand;

  /// Last 4 digits of the card number; `null` when no card is on file.
  final String? last4;

  /// Expiry, e.g. `"08 / 27"`; `null` when no card is on file.
  final String? expiry;

  /// The subscription's next renewal date, formatted `"Jun 1, 2026"`; `null`
  /// when there is no active subscription or the date is unavailable.
  final String? renewalDate;

  const PaymentMethod({
    this.brand,
    this.last4,
    this.expiry,
    this.renewalDate,
  });

  /// Decodes a [PaymentMethod] from the `GET /billing/payment-method`
  /// response (see `backend/app/Http/Controllers/Api/V1/BillingController.php`
  /// `paymentMethod()`). Every field may be `null`, both on a genuine "no
  /// card on file" state and on the endpoint's Stripe-outage soft-fail.
  factory PaymentMethod.fromMap(Map<String, dynamic> map) {
    final int? expMonth = (map['exp_month'] as num?)?.toInt();
    final int? expYear = (map['exp_year'] as num?)?.toInt();

    return PaymentMethod(
      brand: map['brand'] as String?,
      last4: map['last4'] as String?,
      expiry: expMonth != null && expYear != null
          ? '${expMonth.toString().padLeft(2, '0')} / '
                '${(expYear % 100).toString().padLeft(2, '0')}'
          : null,
      renewalDate: _formatOptionalDate(map['renewal_date'] as String?),
    );
  }
}

/// A metered resource shown against its plan limit on the billing page.
///
/// `limit == null` means unlimited. Mirrors the `UsageItem` interface in the
/// React billing mock.
@immutable
class UsageStat {
  /// Display label, e.g. `"Monitors"`.
  final String label;

  /// Current usage count.
  final int used;

  /// Plan limit; `null` = unlimited.
  final int? limit;

  /// Short suffix appended after the numbers, e.g. `""` or `"checks"`.
  final String unit;

  const UsageStat({
    required this.label,
    required this.used,
    required this.limit,
    required this.unit,
  });

  /// Decodes the three [UsageStat]s from a `GET /billing/usage` response
  /// (`{monitors, responders, checks_this_month}`, each `{used, limit}`; see
  /// `BillingController::usage()`), in the screen's existing display order.
  ///
  /// Labels/units are hardcoded English display copy (mirroring the
  /// design-lab fixture's `billingUsage`, which is not localized either), not
  /// wire fields; the wire response carries only the numbers.
  static List<UsageStat> fromWireMap(Map<String, dynamic> map) {
    return [
      _entryFromWire('Monitors', map['monitors'], ''),
      _entryFromWire('Responders', map['responders'], ''),
      _entryFromWire('Checks this month', map['checks_this_month'], 'checks'),
    ];
  }

  /// Decodes one `{used, limit}` wire entry into a [UsageStat] with the
  /// given display [label]/[unit].
  static UsageStat _entryFromWire(
    String label,
    Object? entry,
    String unit,
  ) {
    final Map<String, dynamic> map = entry is Map<String, dynamic>
        ? entry
        : const {};
    return UsageStat(
      label: label,
      used: (map['used'] as num?)?.toInt() ?? 0,
      limit: (map['limit'] as num?)?.toInt(),
      unit: unit,
    );
  }
}
