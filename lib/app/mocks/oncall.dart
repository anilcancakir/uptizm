import 'package:flutter/foundation.dart';

/// One rung of an escalation policy.
///
/// After [afterMinutes] minutes pass without acknowledgement, the policy
/// notifies the listed [targets] and climbs to the next rung.
///
/// ```dart
/// final rung = EscalationStep(afterMinutes: 0, targets: ['Slack #incidents']);
/// print(rung.targets.first); // Slack #incidents
/// ```
@immutable
class EscalationStep {
  /// Minutes to wait after the previous rung fires. 0 means immediately.
  final int afterMinutes;

  /// Human-readable notification targets, e.g. `"Slack #incidents"` or `"On-call (SMS)"`.
  final List<String> targets;

  const EscalationStep({
    required this.afterMinutes,
    required this.targets,
  });
}

/// An escalation policy that turns an unacknowledged alert into a phone call.
///
/// Monitors route to one policy (selected in the monitor form). The policy
/// climbs through [steps] until someone acknowledges the alert. When
/// [repeatLastStep] is true the final rung keeps firing until acknowledgement.
///
/// ```dart
/// final policy = escalationPolicies.first;
/// print('${policy.name}: ${policy.monitorCount} monitors');
/// ```
@immutable
class EscalationPolicy {
  /// Stable identifier, e.g. `'standard'` or `'critical'`.
  final String id;

  /// Human-readable display name shown in selects and headers.
  final String name;

  /// Short description shown in the policy list and form helper text.
  final String description;

  /// Ordered escalation rungs, climbing until someone acknowledges.
  final List<EscalationStep> steps;

  /// When true the last rung keeps repeating until the alert is acknowledged.
  final bool repeatLastStep;

  /// Applied to any monitor that does not pick a policy of its own.
  final bool isDefault;

  /// How many monitors currently route to this policy.
  final int monitorCount;

  const EscalationPolicy({
    required this.id,
    required this.name,
    required this.description,
    required this.steps,
    required this.repeatLastStep,
    required this.isDefault,
    required this.monitorCount,
  });
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/// Design-lab fixture escalation policies. Deterministic; no network.
///
/// Two policies covering the standard and revenue-critical use cases.
const List<EscalationPolicy> escalationPolicies = [
  EscalationPolicy(
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
  EscalationPolicy(
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

/// The default policy a monitor inherits when it has not chosen one explicitly.
///
/// Guaranteed to be a member of [escalationPolicies].
final EscalationPolicy defaultEscalationPolicy = escalationPolicies.firstWhere(
  (p) => p.isDefault,
  orElse: () => escalationPolicies.first,
);

/// Find an escalation policy by [id]. Returns `null` when none matches.
EscalationPolicy? findEscalationPolicy(String? id) {
  if (id == null) return null;
  for (final p in escalationPolicies) {
    if (p.id == id) return p;
  }
  return null;
}
