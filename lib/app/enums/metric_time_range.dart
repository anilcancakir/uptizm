/// Time range options for metric detail views.
enum MetricTimeRange {
  hour1('1h', Duration(hours: 1)),
  hour6('6h', Duration(hours: 6)),
  hour24('24h', Duration(hours: 24)),
  day7('7d', Duration(days: 7)),
  day30('30d', Duration(days: 30));

  const MetricTimeRange(this.label, this.duration);

  final String label;
  final Duration duration;
}
