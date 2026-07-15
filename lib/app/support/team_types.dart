import 'package:flutter/foundation.dart';

import '../enums/channel_type.dart' show ChannelType;
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

/// A team-level notification channel: where alerts route to, and how.
///
/// Mirrors the `NotificationChannel` interface in the React notifications
/// mock. [severity] is `"all"` or `"critical"` (kept as a plain string,
/// matching the React source's minimal severity union).
@immutable
class NotificationChannelConfig {
  /// Stable identifier, one per [ChannelType].
  final String id;

  /// Which delivery channel this row configures.
  final ChannelType type;

  /// Human-readable channel name shown as the row title.
  final String name;

  /// Whether the integration has been set up (connected).
  final bool connected;

  /// Whether alerts are currently delivered here.
  final bool enabled;

  /// Minimum severity this channel delivers: `"all"` or `"critical"`.
  final String severity;

  /// What the channel is pointed at, e.g. a Slack channel, phone number, or
  /// webhook URL. Empty when not yet connected.
  final String detail;

  const NotificationChannelConfig({
    required this.id,
    required this.type,
    required this.name,
    required this.connected,
    required this.enabled,
    required this.severity,
    required this.detail,
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
}

/// A saved payment method shown on the billing page.
///
/// Mirrors the `PaymentMethod` interface in the React billing mock.
@immutable
class PaymentMethod {
  /// Card network/brand, e.g. `"Visa"`.
  final String brand;

  /// Last 4 digits of the card number.
  final String last4;

  /// Expiry, e.g. `"08 / 27"`.
  final String expiry;

  const PaymentMethod({
    required this.brand,
    required this.last4,
    required this.expiry,
  });
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
}
