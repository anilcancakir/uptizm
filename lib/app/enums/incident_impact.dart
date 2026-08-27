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

/// The backend `impact` wire value for a client-side [IncidentImpact].
///
/// The inverse of [impactFromWire], and deliberately not a symmetric one: the
/// backend has four tiers (`none`/`minor`/`major`/`critical`) and this client
/// shows three, because `major` and `critical` both read as "down" to a reader
/// of a status page. Sending `down` therefore has to pick one, and it picks
/// `critical`: that is what a critical severity projects to, so an operator who
/// leaves the select alone lands on the same value the projection would have
/// produced rather than silently downgrading their own incident.
///
/// Exists because the incident form now SENDS this field. Before, the select
/// was collected and discarded, so nothing had to answer which of the two
/// backend tiers a `down` meant.
String impactToWire(IncidentImpact impact) {
  return switch (impact) {
    IncidentImpact.down => 'critical',
    IncidentImpact.degraded => 'minor',
    IncidentImpact.info => 'none',
  };
}
