/// Operator-side severity tier, independent of customer-facing incident impact.
enum IncidentSeverity {
  /// Requires immediate response; business impact is severe.
  critical,

  /// Elevated concern; monitoring closely but not emergency.
  warning,

  /// Low-risk informational event.
  info;

  /// Human-readable display label.
  String get label => switch (this) {
    IncidentSeverity.critical => 'Critical',
    IncidentSeverity.warning => 'Warning',
    IncidentSeverity.info => 'Info',
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
