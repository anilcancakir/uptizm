import 'package:magic/magic.dart';

/// Lifecycle stage an incident moves through.
///
/// Mirrors the backend `IncidentStatus`. The LADDER is
/// detected -> investigating -> identified -> monitoring -> resolved;
/// [mitigated] is not part of it and is not offered anywhere, but it decodes,
/// because the backend still emits it for pre-redesign rows.
enum IncidentLifecycle {
  detected,
  investigating,
  identified,
  monitoring,

  /// A stage the current ladder does not use, retained because the backend
  /// enum still carries it for rows written before the redesign.
  ///
  /// Absent from this enum, `lifecycleFromWire` fell through its `orElse` and
  /// answered [detected] for such a row: an incident somebody had already
  /// mitigated rendered as the EARLIEST rung, in the list badge, the detail
  /// header and the timeline alike, and it landed in the active roster with
  /// that stage attached. The fallback is meant for a stage a newer backend
  /// invents, and it was firing on one this backend has always emitted.
  ///
  /// Deliberately NOT offered in the composer's status select: see
  /// `kIncidentStatuses`, which skips it so an operator cannot move a live
  /// incident onto a rung the product no longer uses.
  mitigated,

  resolved;

  /// Localized display label matching the design source (title-case).
  String get label => switch (this) {
    IncidentLifecycle.detected => trans('uptizm.enums.incident_lifecycle.detected'),
    IncidentLifecycle.investigating => trans('uptizm.enums.incident_lifecycle.investigating'),
    IncidentLifecycle.identified => trans('uptizm.enums.incident_lifecycle.identified'),
    IncidentLifecycle.monitoring => trans('uptizm.enums.incident_lifecycle.monitoring'),
    IncidentLifecycle.mitigated => trans('uptizm.enums.incident_lifecycle.mitigated'),
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
