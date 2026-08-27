import 'package:flutter/widgets.dart' show Color;
import 'package:magic_payments/magic_payments.dart'
    show Invoice, InvoiceStatus, PaymentMethod, UsageStat;

import '../enums/ai_level.dart' show AiLevel;
import '../enums/team_role.dart' show TeamRole;
import '../support/billing_types.dart' show Plan, PlanLimits;
import '../support/team_types.dart' show TeamInvitation, TeamMember;

/// **Teams-domain mock fixtures.**
///
/// Ported from the design lab's `src/lib/teams.ts` (members/invites) and
/// `src/lib/billing.ts` (invoices, payment method, usage). The value-object
/// types live in `lib/app/support/team_types.dart`; this file holds only their
/// fixtures.
///
/// CONSUMED BY TESTS ONLY. This used to say it "feeds the members/billing pages
/// under `lib/resources/views/teams/`", and it does not: `teamMembers`,
/// `pendingInvitations`, `invoices`, `billingUsage`, `paymentMethod` and
/// [kTeamColors] are referenced from no file under `lib/` at all. That matters
/// beyond tidiness, because `.design-token-allowlist` exempts this directory on
/// the stated ground that its contents are "never rendered as real UI", and a
/// docblock claiming the opposite makes that reason unverifiable.
///
/// The on-call rotation fixtures that used to live here are GONE: the on-call
/// screen reads the real `api/v1/on-call/*` surface through
/// `OnCallController`, and a fixture rotation on a paging screen names people
/// who may not exist.
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
// Billing: invoices, payment method, usage
// ---------------------------------------------------------------------------

/// The team's billing history, most recent first.
///
/// Mirrors the `invoices` fixture in the React billing mock.
///
/// `final` rather than `const`: [Invoice.date] is an instant, because month
/// names and date order are display copy the renderer owns, and a [DateTime] can
/// never be a constant.
final List<Invoice> invoices = [
  Invoice(
    id: 'inv-2026-06',
    number: 'INV-2026-06',
    date: DateTime.utc(2026, 6, 1),
    amount: '\$348.00',
    status: InvoiceStatus.paid,
  ),
  Invoice(
    id: 'inv-2025-06',
    number: 'INV-2025-06',
    date: DateTime.utc(2025, 6, 1),
    amount: '\$348.00',
    status: InvoiceStatus.paid,
  ),
  Invoice(
    id: 'inv-2024-09',
    number: 'INV-2024-09',
    date: DateTime.utc(2024, 9, 1),
    amount: '\$34.00',
    status: InvoiceStatus.paid,
  ),
  Invoice(
    id: 'inv-2024-08',
    number: 'INV-2024-08',
    date: DateTime.utc(2024, 8, 1),
    amount: '\$34.00',
    status: InvoiceStatus.failed,
  ),
];

/// The team's on-file payment method.
///
/// Mirrors the `paymentMethod` fixture in the React billing mock. The expiry is
/// the rail's own two numbers rather than a pre-baked `'08 / 27'`; the separator
/// and the two-digit year are a rendering decision.
const PaymentMethod paymentMethod = PaymentMethod(
  brand: 'Visa',
  last4: '4242',
  expMonth: 8,
  expYear: 2027,
);

/// The team's current-cycle usage against its plan limits.
///
/// Monitors and responders sit at or near their `Plan` cap (see `billing.dart`'s
/// Pro-tier limits) so the usage meters read as "close to upgrading";
/// checks-this-month has no hard cap.
///
/// The `key` of each row is the real `GET /billing/usage` wire key, because that
/// is what the entitlement gates look a resource up by; the English `label` is
/// preview copy that a real read pairs on through the catalogue instead (see
/// `withUsageCopy`).
const List<UsageStat> billingUsage = [
  UsageStat(key: 'monitors', label: 'Monitors', used: 47, limit: 50),
  UsageStat(key: 'responders', label: 'Responders', used: 3, limit: 3),
  UsageStat(
    key: 'checks_this_month',
    label: 'Checks this month',
    used: 128400,
    limit: null,
    unit: 'checks',
  ),
];

/// Re-encodes a typed [Plan] catalogue as the `GET /billing/plans` rows a
/// `BillingService` answers with.
///
/// `BillingService.getPlans()` returns `List<Map<String, dynamic>>` verbatim,
/// because a tier's prices, feature bullets and in-product caps are what uptizm
/// sells rather than anything a payment rail understands: [Plan], [PlanLimits]
/// and [AiLevel] live here and every caller decodes the rows itself. So a fake
/// billing service has to answer with rows, while the fixtures worth feeding it
/// (`mocks/billing.dart`'s `plans`, and the one-off catalogues a limits test
/// builds by hand) are typed. This is the bridge, and it sits in the fixture
/// layer beside the other billing fixtures above rather than in any one suite,
/// because five fake services need it and five byte-identical private copies of
/// one serializer is how the copy that drifted feeds one suite a catalogue the
/// others do not have.
///
/// The inverse of [Plan.fromMap], and deliberately TOTAL: every field that
/// decoder reads is written here, so a round trip loses nothing. A field added to
/// [Plan] and not added here would reach a widget under test as the decoder's
/// default instead of as the fixture's value.
List<Map<String, dynamic>> planWireRows(List<Plan> plans) {
  return plans.map(_planWireRow).toList();
}

/// One `data[]` entry of the plan catalogue.
Map<String, dynamic> _planWireRow(Plan plan) {
  return <String, dynamic>{
    'id': plan.id,
    'name': plan.name,
    'tagline': plan.tagline,
    'monthly': plan.monthly,
    'annual': plan.annual,
    'ai_line': plan.aiLine,
    'features': plan.features,
    'responder_add_on': plan.responderAddOn,
    'recommended': plan.recommended,
    'limits': _planLimitsWireRow(plan.limits),
  };
}

/// The nested `limits` object of one catalogue entry.
Map<String, dynamic> _planLimitsWireRow(PlanLimits limits) {
  return <String, dynamic>{
    'monitors': limits.monitors,
    'check_interval_sec': limits.checkIntervalSec,
    'status_pages': limits.statusPages,
    'subscribers': limits.subscribers,
    'responders': limits.responders,
    'regions': limits.regions,
    // The wire word, spelled out, not `.name`: `PlanLimits.fromMap` matches
    // these four values explicitly, and the Dart case names happen to equal them
    // today. Writing `.name` would tie the fixture to that coincidence.
    'ai': switch (limits.ai) {
      AiLevel.inbox => 'inbox',
      AiLevel.analysis => 'analysis',
      AiLevel.auto => 'auto',
      AiLevel.custom => 'custom',
    },
    'white_label': limits.whiteLabel,
    'private_pages': limits.privatePages,
    'sso': limits.sso,
  };
}

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
