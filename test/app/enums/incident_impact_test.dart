import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/incident_impact.dart';

void main() {
  group('IncidentImpact', () {
    test('has majorOutage value', () {
      expect(IncidentImpact.majorOutage.value, 'major_outage');
      expect(IncidentImpact.majorOutage.label, 'Major Outage');
    });

    test('has partialOutage value', () {
      expect(IncidentImpact.partialOutage.value, 'partial_outage');
      expect(IncidentImpact.partialOutage.label, 'Partial Outage');
    });

    test('has degradedPerformance value', () {
      expect(IncidentImpact.degradedPerformance.value, 'degraded_performance');
      expect(IncidentImpact.degradedPerformance.label, 'Degraded Performance');
    });

    test('has underMaintenance value', () {
      expect(IncidentImpact.underMaintenance.value, 'under_maintenance');
      expect(IncidentImpact.underMaintenance.label, 'Under Maintenance');
    });

    test('fromValue returns correct impact', () {
      expect(
        IncidentImpact.fromValue('major_outage'),
        IncidentImpact.majorOutage,
      );
      expect(
        IncidentImpact.fromValue('partial_outage'),
        IncidentImpact.partialOutage,
      );
      expect(
        IncidentImpact.fromValue('degraded_performance'),
        IncidentImpact.degradedPerformance,
      );
      expect(
        IncidentImpact.fromValue('under_maintenance'),
        IncidentImpact.underMaintenance,
      );
    });

    test('fromValue returns null for invalid value', () {
      expect(IncidentImpact.fromValue('invalid'), isNull);
      expect(IncidentImpact.fromValue(null), isNull);
    });

    test('selectOptions includes all impacts', () {
      final options = IncidentImpact.selectOptions;
      expect(options.length, 4);
      expect(
        options.any((opt) => opt.value == IncidentImpact.majorOutage),
        true,
      );
      expect(
        options.any((opt) => opt.value == IncidentImpact.partialOutage),
        true,
      );
      expect(
        options.any((opt) => opt.value == IncidentImpact.degradedPerformance),
        true,
      );
      expect(
        options.any((opt) => opt.value == IncidentImpact.underMaintenance),
        true,
      );
    });

    test('color getter returns correct colors', () {
      expect(IncidentImpact.majorOutage.color, 'red');
      expect(IncidentImpact.partialOutage.color, 'orange');
      expect(IncidentImpact.degradedPerformance.color, 'yellow');
      expect(IncidentImpact.underMaintenance.color, 'blue');
    });

    test('icon getter returns correct icons', () {
      expect(IncidentImpact.majorOutage.icon, 'close');
      expect(IncidentImpact.partialOutage.icon, 'warning');
      expect(IncidentImpact.degradedPerformance.icon, 'speed');
      expect(IncidentImpact.underMaintenance.icon, 'build');
    });
  });
}
