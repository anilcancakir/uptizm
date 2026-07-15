import 'status_key.dart';

/// Customer-facing impact level; a subset of [StatusKey].
///
/// Only statuses that meaningfully describe visible impact are included:
/// service is completely down, partially degraded, or in a planned maintenance
/// window.
enum IncidentImpact {
  /// Service is completely unavailable.
  down,

  /// Service is slower or partially impaired.
  degraded,

  /// Planned maintenance; no unexpected impact.
  info;

  /// Maps to the equivalent [StatusKey] for badge rendering.
  StatusKey get statusKey => switch (this) {
    IncidentImpact.down => StatusKey.down,
    IncidentImpact.degraded => StatusKey.degraded,
    IncidentImpact.info => StatusKey.info,
  };
}

/// Derives the customer-facing [IncidentImpact] badge from the backend
/// `impact` wire value (`none`/`minor`/`major`/`critical`), the vocabulary
/// the mock's three-value [IncidentImpact] does not share 1:1: `critical`
/// and `major` both read as a full [IncidentImpact.down], `minor` reads as
/// [IncidentImpact.degraded], and `none` (or anything unrecognized) reads
/// as [IncidentImpact.info].
IncidentImpact impactFromWire(String? raw) {
  return switch (raw) {
    'critical' || 'major' => IncidentImpact.down,
    'minor' => IncidentImpact.degraded,
    'none' => IncidentImpact.info,
    _ => IncidentImpact.info,
  };
}
