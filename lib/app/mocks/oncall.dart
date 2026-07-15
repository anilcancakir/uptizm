import '../support/oncall_types.dart' show EscalationPreset, EscalationStep;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/// Design-lab fixture escalation presets. Deterministic; no network.
///
/// Two presets covering the standard and revenue-critical use cases.
const List<EscalationPreset> escalationPolicies = [
  EscalationPreset(
    id: 'standard',
    name: 'Standard',
    description: 'Balanced response for most services.',
    steps: [
      EscalationStep(afterMinutes: 0, targets: ['Slack #incidents', 'Email team']),
      EscalationStep(afterMinutes: 5, targets: ['On-call engineer']),
      EscalationStep(afterMinutes: 15, targets: ['Team admins']),
    ],
    repeatLastStep: true,
    isDefault: true,
    monitorCount: 3,
  ),
  EscalationPreset(
    id: 'critical',
    name: 'Critical path',
    description: 'Aggressive paging for revenue-critical monitors like checkout.',
    steps: [
      EscalationStep(afterMinutes: 0, targets: ['On-call engineer', 'Slack #incidents']),
      EscalationStep(afterMinutes: 3, targets: ['Secondary on-call']),
      EscalationStep(afterMinutes: 10, targets: ['Team admins', 'PagerDuty']),
    ],
    repeatLastStep: true,
    isDefault: false,
    monitorCount: 1,
  ),
];

/// The default preset a monitor inherits when it has not chosen one explicitly.
///
/// Guaranteed to be a member of [escalationPolicies].
final EscalationPreset defaultEscalationPolicy = escalationPolicies.firstWhere(
  (p) => p.isDefault,
  orElse: () => escalationPolicies.first,
);

/// Find an escalation preset by [id]. Returns `null` when none matches.
EscalationPreset? findEscalationPolicy(String? id) {
  if (id == null) return null;
  for (final p in escalationPolicies) {
    if (p.id == id) return p;
  }
  return null;
}
