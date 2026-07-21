import 'package:magic/magic.dart';

/// Lifecycle stage an incident moves through.
enum IncidentLifecycle {
  detected,
  investigating,
  identified,
  monitoring,
  resolved;

  /// Localized display label matching the design source (title-case).
  String get label => switch (this) {
    IncidentLifecycle.detected => trans('uptizm.enums.incident_lifecycle.detected'),
    IncidentLifecycle.investigating => trans('uptizm.enums.incident_lifecycle.investigating'),
    IncidentLifecycle.identified => trans('uptizm.enums.incident_lifecycle.identified'),
    IncidentLifecycle.monitoring => trans('uptizm.enums.incident_lifecycle.monitoring'),
    IncidentLifecycle.resolved => trans('uptizm.enums.incident_lifecycle.resolved'),
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
