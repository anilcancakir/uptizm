import 'package:flutter/widgets.dart' show Color, immutable;

import '../../ui/components/region_picker/region_picker.dart' show Region;
import '../enums/channel_type.dart' show ChannelType;
import '../enums/invoice_status.dart' show InvoiceStatus;
import '../enums/team_role.dart' show TeamRole;

/// **Teams-domain mock fixtures.**
///
/// Ported from the design lab's `src/lib/teams.ts` (members/invites),
/// `src/lib/notifications.ts` (channels), `src/lib/oncall.ts` (rotation +
/// delay label), and `src/lib/billing.ts` (invoices, payment method, usage).
/// Feeds the members/notifications/on-call/billing/escalation-editor pages
/// under `lib/resources/views/teams/`.
///
/// This file does NOT redefine [Team]/[CurrentUser] (`teams.dart`),
/// [EscalationPolicy]/[EscalationStep] (`oncall.dart`), or [Plan]
/// (`billing.dart`); it only adds the fixtures those files do not carry.
///
/// [kTeamColors] is content data (a per-team/avatar brand-color palette), the
/// direct analogue of `Team.color`/`StatusPageConfig.brandColor`, so it lives
/// as raw [Color] values, NOT semantic Wind tokens.

// ---------------------------------------------------------------------------
// Members + invitations
// ---------------------------------------------------------------------------

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

/// The current team's members, owner-first. Exactly one [TeamMember.isSelf].
///
/// Mirrors the `members` fixture in the React teams mock.
const List<TeamMember> teamMembers = [
  TeamMember(
    id: 'u1',
    name: 'Anılcan Çakır',
    email: 'anil@acme.com',
    initials: 'AÇ',
    role: TeamRole.owner,
    joinedAt: '1 year ago',
    isSelf: true,
  ),
  TeamMember(
    id: 'u2',
    name: 'Mara Pohl',
    email: 'mara@acme.com',
    initials: 'MP',
    role: TeamRole.admin,
    joinedAt: '8 months ago',
    isSelf: false,
  ),
  TeamMember(
    id: 'u3',
    name: 'Ravi Shah',
    email: 'ravi@acme.com',
    initials: 'RS',
    role: TeamRole.member,
    joinedAt: '5 months ago',
    isSelf: false,
  ),
  TeamMember(
    id: 'u4',
    name: 'Ada Lovelace',
    email: 'ada@acme.com',
    initials: 'AL',
    role: TeamRole.member,
    joinedAt: '3 months ago',
    isSelf: false,
  ),
];

/// Pending invitations awaiting acceptance.
///
/// Mirrors the `pendingInvites` fixture in the React teams mock.
const List<TeamInvitation> pendingInvitations = [
  TeamInvitation(
    id: 'inv1',
    email: 'sam@acme.com',
    role: TeamRole.member,
    invitedAt: '2 days ago',
  ),
  TeamInvitation(
    id: 'inv2',
    email: 'priya@acme.com',
    role: TeamRole.admin,
    invitedAt: '1 week ago',
  ),
];

// ---------------------------------------------------------------------------
// Notification channels
// ---------------------------------------------------------------------------

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

/// Team-level notification channels, one per [ChannelType].
///
/// Mirrors the `channels` fixture in the React notifications mock.
const List<NotificationChannelConfig> notificationChannels = [
  NotificationChannelConfig(
    id: 'email',
    type: ChannelType.email,
    name: 'Email',
    connected: true,
    enabled: true,
    severity: 'all',
    detail: '4 team members',
  ),
  NotificationChannelConfig(
    id: 'sms',
    type: ChannelType.sms,
    name: 'SMS',
    connected: true,
    enabled: true,
    severity: 'critical',
    detail: '+1 555 0142',
  ),
  NotificationChannelConfig(
    id: 'slack',
    type: ChannelType.slack,
    name: 'Slack',
    connected: true,
    enabled: true,
    severity: 'all',
    detail: '#incidents · Acme',
  ),
  NotificationChannelConfig(
    id: 'teams',
    type: ChannelType.teams,
    name: 'Microsoft Teams',
    connected: false,
    enabled: false,
    severity: 'all',
    detail: '',
  ),
  NotificationChannelConfig(
    id: 'webhook',
    type: ChannelType.webhook,
    name: 'Webhook',
    connected: true,
    enabled: false,
    severity: 'all',
    detail: 'https://hooks.acme.dev/uptizm',
  ),
];

// ---------------------------------------------------------------------------
// On-call rotation
// ---------------------------------------------------------------------------

/// A block of rotation time a member is the responder.
///
/// Denormalized from [TeamMember] (id/name/initials copied in) so the
/// on-call schedule view does not need to join against [teamMembers] to
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

/// The team's on-call rotation. Exactly one shift is [OnCallShift.current].
///
/// Mirrors the `onCallRotation` fixture in the React oncall mock.
const List<OnCallShift> onCallRotation = [
  OnCallShift(
    memberId: 'u2',
    memberName: 'Mara Pohl',
    initials: 'MP',
    span: 'Mon 09:00 - Wed 09:00',
    current: true,
  ),
  OnCallShift(
    memberId: 'u3',
    memberName: 'Ravi Shah',
    initials: 'RS',
    span: 'Wed 09:00 - Fri 09:00',
    current: false,
  ),
  OnCallShift(
    memberId: 'u4',
    memberName: 'Ada Lovelace',
    initials: 'AL',
    span: 'Fri 09:00 - Mon 09:00',
    current: false,
  ),
];

/// Rotation cadence, shown on the on-call schedule screen.
///
/// Mirrors the `onCallCadence` fixture in the React oncall mock.
const String onCallCadence = 'Weekly handoff, Mondays at 09:00';

// ---------------------------------------------------------------------------
// Billing: invoices, payment method, usage
// ---------------------------------------------------------------------------

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

/// The team's billing history, most recent first.
///
/// Mirrors the `invoices` fixture in the React billing mock.
const List<Invoice> invoices = [
  Invoice(
    id: 'inv-2026-06',
    number: 'INV-2026-06',
    date: 'Jun 1, 2026',
    amount: '\$348.00',
    status: InvoiceStatus.paid,
  ),
  Invoice(
    id: 'inv-2025-06',
    number: 'INV-2025-06',
    date: 'Jun 1, 2025',
    amount: '\$348.00',
    status: InvoiceStatus.paid,
  ),
  Invoice(
    id: 'inv-2024-09',
    number: 'INV-2024-09',
    date: 'Sep 1, 2024',
    amount: '\$34.00',
    status: InvoiceStatus.paid,
  ),
  Invoice(
    id: 'inv-2024-08',
    number: 'INV-2024-08',
    date: 'Aug 1, 2024',
    amount: '\$34.00',
    status: InvoiceStatus.failed,
  ),
];

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

/// The team's on-file payment method.
///
/// Mirrors the `paymentMethod` fixture in the React billing mock.
const PaymentMethod paymentMethod = PaymentMethod(
  brand: 'Visa',
  last4: '4242',
  expiry: '08 / 27',
);

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

/// The team's current-cycle usage against its plan limits.
///
/// Monitors and responders sit at or near their [Plan] cap (see
/// `billing.dart`'s Pro-tier limits) so the usage meters read as "close to
/// upgrading"; checks-this-month has no hard cap.
const List<UsageStat> billingUsage = [
  UsageStat(label: 'Monitors', used: 47, limit: 50, unit: ''),
  UsageStat(label: 'Responders', used: 3, limit: 3, unit: ''),
  UsageStat(label: 'Checks this month', used: 128400, limit: null, unit: 'checks'),
];

// ---------------------------------------------------------------------------
// Team avatar palette
// ---------------------------------------------------------------------------

/// Preset team/avatar brand colors offered in the team create/settings
/// branding swatch grid.
///
/// Content data (the `Team.color`/`StatusPageConfig.brandColor` precedent),
/// NOT a semantic Wind token; there is no alias for an arbitrary per-tenant
/// color. Mirrors the `TEAM_COLORS` fixture in the React teams mock.
const List<Color> kTeamColors = [
  Color(0xFF16A34A),
  Color(0xFF2563EB),
  Color(0xFF6366F1),
  Color(0xFF7C3AED),
  Color(0xFFDB2777),
  Color(0xFFE11D48),
  Color(0xFFEA580C),
  Color(0xFF0D9488),
];

// ---------------------------------------------------------------------------
// Escalation editor helpers
// ---------------------------------------------------------------------------

/// The recipients an escalation rung can page.
///
/// A rung decides WHO and WHEN; HOW each recipient is reached (SMS, call,
/// Slack) lives in Notification channels and the person's own rules. The
/// label doubles as the stored [EscalationStep] target value (see
/// `oncall.dart`). Mirrors `TARGET_OPTIONS` in the React escalation editor.
const List<String> _escalationTargets = [
  'Slack #incidents',
  'Email team',
  'On-call engineer',
  'Secondary on-call',
  'Team admins',
  'PagerDuty',
];

/// Escalation-rung target options for the [RegionPicker] multi-select.
///
/// Each [Region.value] is the exact string stored in
/// `EscalationStep.targets`. Mirrors `TARGET_OPTIONS` in the React
/// `EscalationPolicyEditor`.
///
/// ```dart
/// RegionPicker(regions: escalationTargetRegions(), value: step.targets, ...)
/// ```
List<Region> escalationTargetRegions() {
  return [for (final String target in _escalationTargets) Region(label: target, value: target)];
}

/// Turns a rung delay into its display label.
///
/// `0` minutes reads as "Immediately"; anything else composes "After :n min".
/// Mirrors `escalationDelayLabel` in the React oncall mock. No i18n: the
/// label is assembled directly, matching the source's plain template string.
///
/// ```dart
/// escalationDelayLabel(0); // "Immediately"
/// escalationDelayLabel(5); // "After 5 min"
/// ```
String escalationDelayLabel(int afterMinutes) {
  if (afterMinutes == 0) return 'Immediately';
  return 'After $afterMinutes min';
}
