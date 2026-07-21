import 'package:magic/magic.dart';

/// How the incident was first detected.
enum SignalSource {
  /// A configured numeric threshold was breached.
  threshold,

  /// Uptizm AI detected an anomaly in a learned baseline.
  anomaly,

  /// Created manually by an operator.
  manual;

  /// Localized display label for timeline and filter chips.
  String get label => switch (this) {
    SignalSource.threshold => trans('uptizm.enums.signal_source.threshold'),
    SignalSource.anomaly => trans('uptizm.enums.signal_source.anomaly'),
    SignalSource.manual => trans('uptizm.enums.signal_source.manual'),
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
