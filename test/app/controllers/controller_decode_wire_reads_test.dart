import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/controllers/monitor_metrics_controller.dart';
import 'package:uptizm/app/controllers/notification_channel_controller.dart';

/// What these pin: the controller-level decoders degrade a wrong-typed wire
/// field instead of throwing.
///
/// `MonitorMetricRecord.fromMap` already knew: its `latestRecordedAt` reads
/// through a `switch` type test carrying a comment that says a crashing decoder
/// is worse than one treating a malformed field as absent, because "the tab
/// would show nothing at all rather than a reading it cannot date". Every
/// sibling field in the same constructor kept the cast. The file argued for the
/// fix and did not apply it.
void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  test('MonitorMetricRecord keeps the metric when one bound is unreadable', () {
    final MonitorMetricRecord record = MonitorMetricRecord.fromMap(
      <String, dynamic>{
        'id': 'metric-1',
        'label': 'Queue depth',
        'key': 'queue_depth',
        'type': 'numeric',
        'warn_bound': 'high',
        'critical_bound': 500,
        'latest': <String, dynamic>{'numeric_value': 12, 'band': 7},
      },
    );

    expect(record.form.label, 'Queue depth');
    // The unreadable bound reads as "no bound set" rather than taking the whole
    // metrics tab down with it.
    expect(record.form.warn, '');
    expect(record.form.critical, '500');
    expect(record.form.value, 12);
    // A non-string band on the latest reading blanks the dot, not the row.
    expect(record.latestBand, isNull);
  });

  test('MonitorAnalysis survives an object where the arrays belong', () {
    final MonitorAnalysis analysis = MonitorAnalysis.fromMap(
      <String, dynamic>{
        'name': 'API',
        'rationale': 'Public health endpoint, answer under 300ms.',
        'recommended_regions': <String, dynamic>{'unexpected': 'object'},
        'suggested_metrics': 'not-a-list',
      },
    );

    // `whereType` already dropped a wrongly-typed ELEMENT; the container itself
    // was the gap, and it took the rationale down with the regions.
    expect(analysis.recommendedRegions, isEmpty);
    expect(analysis.suggestedMetrics, isEmpty);
    expect(analysis.rationale, 'Public health endpoint, answer under 300ms.');
  });

  test('an alert channel stays enabled when its flag is unreadable', () {
    final NotificationChannelRecord record = NotificationChannelRecord.fromMap(
      <String, dynamic>{
        'id': 'ch-1',
        'channel_type': 'slack',
        'name': 'Ops room',
        'is_enabled': 'yes',
        'severity': 3,
        'credentials': <String, dynamic>{'has_token': true, 'channel': '#ops'},
      },
    );

    expect(record.name, 'Ops room');
    // True is the safe fallback for an ALERT channel specifically: an
    // unreadable flag that defaulted off would stop paging silently, which is
    // the failure the whole product exists to prevent.
    expect(record.isEnabled, isTrue);
    expect(record.severity, 'all');
    expect(record.hasCredentials, isTrue);
    expect(record.detail, '#ops');
  });
}
