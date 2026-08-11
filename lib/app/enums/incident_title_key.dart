/// Which sentence the backend composed an incident's title from, mirroring the
/// six key constants on `App\Services\Monitoring\IncidentTitle`.
///
/// The backend persists the key next to the English render, so a localized
/// reader renders the sentence itself instead of the prose in the column. This
/// enum is the client half of that contract: `Incident.displayTitle` maps a
/// member onto the matching `uptizm.incidents.title_*` catalogue entry.
enum IncidentTitleKey {
  /// A monitor crossed its consecutive-failure threshold: a plain outage.
  monitorDown,

  /// A numeric metric sample landed in its warn band.
  metricWarnBound,

  /// A numeric metric sample landed in its critical band.
  ///
  /// The two bound cases are separate members rather than one member plus a
  /// severity parameter, because the band name is part of the sentence and a
  /// parameter would carry the English word into the Turkish render.
  metricCriticalBound,

  /// A string metric reported a value the operator configured as a breach.
  metricStringValue,

  /// A monitor's TLS certificate is near expiry.
  ///
  /// The wire value and this member are BARE: the day count picks between the
  /// `_one` and `_other` catalogue entries at render time, exactly as the
  /// backend resolver does, so nothing suffixed ever crosses the wire.
  sslExpiring,

  /// The anomaly detector opened the incident rather than a configured bound.
  aiAnomaly;

  /// The `uptizm.incidents.title_*` catalogue entry this key renders from.
  ///
  /// [params] is only read for [sslExpiring], whose sentence needs "1 day"
  /// against "N days" in English. Laravel's `trans_choice()` has no counterpart
  /// in magic's `trans()`, so both halves pick a suffixed entry from the `days`
  /// value with the same `== 1` test rather than through a pluralization rule.
  /// The count arrives as a `jsonb` number or as a string depending on the
  /// column's round trip, hence the parse over a typed compare.
  String catalogueKey(Map<String, String> params) => switch (this) {
    IncidentTitleKey.monitorDown => 'uptizm.incidents.title_monitor_down',
    IncidentTitleKey.metricWarnBound =>
      'uptizm.incidents.title_metric_warn_bound',
    IncidentTitleKey.metricCriticalBound =>
      'uptizm.incidents.title_metric_critical_bound',
    IncidentTitleKey.metricStringValue =>
      'uptizm.incidents.title_metric_string_value',
    IncidentTitleKey.sslExpiring =>
      int.tryParse(params['days'] ?? '') == 1
          ? 'uptizm.incidents.title_ssl_expiring_one'
          : 'uptizm.incidents.title_ssl_expiring_other',
    IncidentTitleKey.aiAnomaly => 'uptizm.incidents.title_ai_anomaly',
  };
}

/// Decodes the backend `title_key` wire value into an [IncidentTitleKey].
///
/// `null` in means `null` out: an absent key is the backend saying a human
/// authored this title, which is a REAL state and the reason old rows need no
/// backfill. The caller renders the stored `title` for it.
///
/// An UNRECOGNISED non-null value also answers `null`, and that is the one real
/// decision here. It is the opposite of `aiDegradeReasonFromWire`, which falls
/// back to a case: there, nothing was stored to fall back TO, so a client older
/// than the backend had to name some degradation or silently present a baseline
/// as the model's answer. Here the backend persists the English render of the
/// same sentence in `title`, so answering `null` renders a real sentence the
/// backend composed. Guessing a member instead would render a DIFFERENT
/// sentence, with parameters that may not even belong to it.
///
/// The values are matched EXPLICITLY rather than against `.name`: the backend
/// keys are dotted and snake_case (`'incidents.monitor_down'`) where Dart is
/// camelCase, so a `.name` comparison would miss all six.
IncidentTitleKey? incidentTitleKeyFromWire(String? raw) {
  if (raw == null) return null;

  return switch (raw) {
    'incidents.monitor_down' => IncidentTitleKey.monitorDown,
    'incidents.metric_warn_bound' => IncidentTitleKey.metricWarnBound,
    'incidents.metric_critical_bound' => IncidentTitleKey.metricCriticalBound,
    'incidents.metric_string_value' => IncidentTitleKey.metricStringValue,
    'incidents.ssl_expiring' => IncidentTitleKey.sslExpiring,
    'incidents.ai_anomaly' => IncidentTitleKey.aiAnomaly,
    _ => null,
  };
}
