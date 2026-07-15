/// Whether a higher or lower metric reading constitutes a worse state.
enum MetricDirection {
  /// Higher values are more concerning (CPU, latency, error rate).
  high,

  /// Lower values are more concerning (queue headroom, throughput).
  low,
}
