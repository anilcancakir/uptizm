import '../../ui/components/region_picker/region_picker.dart' show Region;

/// The recipients an escalation rung can page.
///
/// A rung decides WHO and WHEN; HOW each recipient is reached (SMS, call,
/// Slack) lives in Notification channels and the person's own rules. The
/// label doubles as the stored `EscalationStep` target value. Mirrors
/// `TARGET_OPTIONS` in the React escalation editor.
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
/// Each [Region.value] is the exact string stored in `EscalationStep.targets`.
/// Mirrors `TARGET_OPTIONS` in the React `EscalationPolicyEditor`.
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
