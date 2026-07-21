import 'package:magic/magic.dart';

/// Operator-side severity tier, independent of customer-facing incident impact.
enum IncidentSeverity {
  /// Requires immediate response; business impact is severe.
  critical,

  /// Elevated concern; monitoring closely but not emergency.
  warning,

  /// Low-risk informational event.
  info;

  /// Localized human-readable display label.
  String get label => switch (this) {
    IncidentSeverity.critical => trans('uptizm.enums.incident_severity.critical'),
    IncidentSeverity.warning => trans('uptizm.enums.incident_severity.warning'),
    IncidentSeverity.info => trans('uptizm.enums.incident_severity.info'),
  };
}

/// Decodes the backend `severity` wire value into an [IncidentSeverity].
///
/// The backend uses `'warn'` where the mock enum spells out `warning`;
/// everything else falls back to [IncidentSeverity.info].
IncidentSeverity severityFromWire(String? raw) {
  return switch (raw) {
    'critical' => IncidentSeverity.critical,
    'warn' => IncidentSeverity.warning,
    'info' => IncidentSeverity.info,
    _ => IncidentSeverity.info,
  };
}
