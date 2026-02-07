import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/incident_status.dart';
import 'package:uptizm/app/models/incident_update.dart';

void main() {
  group('IncidentUpdate', () {
    test('fillable includes required fields', () {
      final update = IncidentUpdate();
      expect(update.fillable.contains('incident_id'), true);
      expect(update.fillable.contains('status'), true);
      expect(update.fillable.contains('title'), true);
      expect(update.fillable.contains('message'), true);
    });

    test('table is correct', () {
      final update = IncidentUpdate();
      expect(update.table, 'incident_updates');
    });

    test('id getter returns string', () {
      final update = IncidentUpdate();
      update.setRawAttributes({'id': 'uuid-456'}, sync: true);
      expect(update.id, 'uuid-456');
    });

    test('incidentId getter returns string', () {
      final update = IncidentUpdate();
      update.setRawAttributes({'incident_id': 'incident-123'}, sync: true);
      expect(update.incidentId, 'incident-123');
    });

    test('status getter returns enum', () {
      final update = IncidentUpdate();
      update.setRawAttributes({'status': 'identified'}, sync: true);
      expect(update.status, IncidentStatus.identified);
    });

    test('status setter stores value', () {
      final update = IncidentUpdate();
      update.status = IncidentStatus.monitoring;
      expect(update.getAttribute('status'), 'monitoring');
    });

    test('title getter returns string', () {
      final update = IncidentUpdate();
      update.setRawAttributes({'title': 'Update title'}, sync: true);
      expect(update.title, 'Update title');
    });

    test('title setter stores value', () {
      final update = IncidentUpdate();
      update.title = 'New title';
      expect(update.getAttribute('title'), 'New title');
    });

    test('message getter returns string', () {
      final update = IncidentUpdate();
      update.setRawAttributes({
        'message': 'We are investigating...',
      }, sync: true);
      expect(update.message, 'We are investigating...');
    });

    test('message setter stores value', () {
      final update = IncidentUpdate();
      update.message = 'Issue resolved';
      expect(update.getAttribute('message'), 'Issue resolved');
    });

    test('handles null title gracefully', () {
      final update = IncidentUpdate();
      update.setRawAttributes({}, sync: true);
      expect(update.title, isNull);
    });
  });
}
