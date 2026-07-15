/// Lifecycle stage an incident moves through.
enum IncidentLifecycle {
  detected,
  investigating,
  identified,
  monitoring,
  resolved;

  /// Display label matching the design source (title-case).
  String get label => switch (this) {
    IncidentLifecycle.detected => 'Detected',
    IncidentLifecycle.investigating => 'Investigating',
    IncidentLifecycle.identified => 'Identified',
    IncidentLifecycle.monitoring => 'Monitoring',
    IncidentLifecycle.resolved => 'Resolved',
  };
}

/// Decodes the backend `lifecycle` wire value into an [IncidentLifecycle],
/// falling back to [IncidentLifecycle.detected] on an unknown value so a
/// stale client never crashes on a lifecycle stage it does not yet know.
IncidentLifecycle lifecycleFromWire(String? raw) {
  if (raw == null) return IncidentLifecycle.detected;
  return IncidentLifecycle.values.firstWhere(
    (v) => v.name == raw,
    orElse: () => IncidentLifecycle.detected,
  );
}
