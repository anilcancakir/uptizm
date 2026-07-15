/// How the incident was first detected.
enum SignalSource {
  /// A configured numeric threshold was breached.
  threshold,

  /// Uptizm AI detected an anomaly in a learned baseline.
  anomaly,

  /// Created manually by an operator.
  manual;

  /// Human-readable display label for timeline and filter chips.
  String get label => switch (this) {
    SignalSource.threshold => 'Threshold breach',
    SignalSource.anomaly => 'AI anomaly',
    SignalSource.manual => 'Manual',
  };
}

/// Decodes the backend `signal_source` wire value into a [SignalSource],
/// falling back to [SignalSource.manual] on an unknown value.
SignalSource signalSourceFromWire(String? raw) {
  return switch (raw) {
    'user_threshold' => SignalSource.threshold,
    'ai_anomaly' => SignalSource.anomaly,
    'manual' => SignalSource.manual,
    _ => SignalSource.manual,
  };
}
