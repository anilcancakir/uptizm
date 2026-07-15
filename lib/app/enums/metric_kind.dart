/// Collection method for a metric.
enum MetricKind {
  /// Collected automatically from every monitor (response time, error rate).
  system,

  /// Defined by the user pointing at a custom endpoint.
  custom,
}
