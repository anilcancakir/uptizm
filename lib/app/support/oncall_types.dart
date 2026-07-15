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

/// A design-lab escalation preset that turns an unacknowledged alert into a
/// phone call.
///
/// Named [EscalationPreset] (not `EscalationPolicy`) to avoid clashing with the
/// `EscalationPolicy` ORM model in `lib/app/models/escalation_policy.dart`;
/// this is the fixture-only DTO that backs the monitor-form policy picker.
/// Monitors route to one preset (selected in the monitor form). The preset
/// climbs through [steps] until someone acknowledges the alert. When
/// [repeatLastStep] is true the final rung keeps firing until acknowledgement.
@immutable
class EscalationPreset {
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

  /// Applied to any monitor that does not pick a preset of its own.
  final bool isDefault;

  /// How many monitors currently route to this preset.
  final int monitorCount;

  const EscalationPreset({
    required this.id,
    required this.name,
    required this.description,
    required this.steps,
    required this.repeatLastStep,
    required this.isDefault,
    required this.monitorCount,
  });
}
