import 'package:flutter/widgets.dart' show Color;

import '../enums/team_role.dart' show TeamRole;
import '../support/team_types.dart'
    show
        Invoice,
        OnCallShift,
        PaymentMethod,
        TeamInvitation,
        TeamMember,
        UsageStat;
import '../enums/invoice_status.dart' show InvoiceStatus;

/// **Teams-domain mock fixtures.**
///
/// Ported from the design lab's `src/lib/teams.ts` (members/invites),
/// `src/lib/notifications.ts` (channels), `src/lib/oncall.ts` (rotation), and
/// `src/lib/billing.ts` (invoices, payment method, usage). Feeds the
/// members/notifications/on-call/billing pages under
/// `lib/resources/views/teams/`. The value-object types live in
/// `lib/app/support/team_types.dart`; this file holds only their fixtures.
///
/// [kTeamColors] is content data (a per-team/avatar brand-color palette), the
/// direct analogue of `Team.color`/`StatusPageConfig.brandColor`, so it lives
/// as raw [Color] values, NOT semantic Wind tokens.

// ---------------------------------------------------------------------------
// Members + invitations
// ---------------------------------------------------------------------------

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
// On-call rotation
// ---------------------------------------------------------------------------

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

/// The team's on-file payment method.
///
/// Mirrors the `paymentMethod` fixture in the React billing mock.
const PaymentMethod paymentMethod = PaymentMethod(
  brand: 'Visa',
  last4: '4242',
  expiry: '08 / 27',
);

/// The team's current-cycle usage against its plan limits.
///
/// Monitors and responders sit at or near their `Plan` cap (see `billing.dart`'s
/// Pro-tier limits) so the usage meters read as "close to upgrading";
/// checks-this-month has no hard cap.
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
