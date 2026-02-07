import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/incident_status.dart';

void main() {
  group('IncidentStatus', () {
    test('has investigating value', () {
      expect(IncidentStatus.investigating.value, 'investigating');
      expect(IncidentStatus.investigating.label, 'Investigating');
    });

    test('has identified value', () {
      expect(IncidentStatus.identified.value, 'identified');
      expect(IncidentStatus.identified.label, 'Identified');
    });

    test('has monitoring value', () {
      expect(IncidentStatus.monitoring.value, 'monitoring');
      expect(IncidentStatus.monitoring.label, 'Monitoring');
    });

    test('has resolved value', () {
      expect(IncidentStatus.resolved.value, 'resolved');
      expect(IncidentStatus.resolved.label, 'Resolved');
    });

    test('fromValue returns correct status', () {
      expect(
        IncidentStatus.fromValue('investigating'),
        IncidentStatus.investigating,
      );
      expect(IncidentStatus.fromValue('identified'), IncidentStatus.identified);
      expect(IncidentStatus.fromValue('monitoring'), IncidentStatus.monitoring);
      expect(IncidentStatus.fromValue('resolved'), IncidentStatus.resolved);
    });

    test('fromValue returns null for invalid value', () {
      expect(IncidentStatus.fromValue('invalid'), isNull);
      expect(IncidentStatus.fromValue(null), isNull);
    });

    test('selectOptions includes all statuses', () {
      final options = IncidentStatus.selectOptions;
      expect(options.length, 4);
      expect(
        options.any((opt) => opt.value == IncidentStatus.investigating),
        true,
      );
      expect(
        options.any((opt) => opt.value == IncidentStatus.identified),
        true,
      );
      expect(
        options.any((opt) => opt.value == IncidentStatus.monitoring),
        true,
      );
      expect(options.any((opt) => opt.value == IncidentStatus.resolved), true);
    });

    test('color getter returns correct colors', () {
      expect(IncidentStatus.investigating.color, 'gray');
      expect(IncidentStatus.identified.color, 'orange');
      expect(IncidentStatus.monitoring.color, 'blue');
      expect(IncidentStatus.resolved.color, 'green');
    });
  });
}
