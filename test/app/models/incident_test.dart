import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/incident_impact.dart';
import 'package:uptizm/app/enums/incident_status.dart';
import 'package:uptizm/app/models/incident.dart';

void main() {
  group('Incident', () {
    test('fillable includes required fields', () {
      final incident = Incident();
      expect(incident.fillable.contains('title'), true);
      expect(incident.fillable.contains('impact'), true);
      expect(incident.fillable.contains('status'), true);
      expect(incident.fillable.contains('is_auto_created'), true);
      expect(incident.fillable.contains('started_at'), true);
      expect(incident.fillable.contains('resolved_at'), true);
      expect(incident.fillable.contains('monitor_ids'), true);
    });

    test('table and resource are correct', () {
      final incident = Incident();
      expect(incident.table, 'incidents');
      expect(incident.resource, 'incidents');
    });

    test('incrementing is false', () {
      final incident = Incident();
      expect(incident.incrementing, false);
    });

    test('id getter returns string', () {
      final incident = Incident();
      incident.setRawAttributes({'id': 'uuid-123'}, sync: true);
      expect(incident.id, 'uuid-123');
    });

    test('title getter and setter work', () {
      final incident = Incident();
      incident.title = 'Database Down';
      expect(incident.title, 'Database Down');
    });

    test('impact getter returns enum', () {
      final incident = Incident();
      incident.setRawAttributes({'impact': 'major_outage'}, sync: true);
      expect(incident.impact, IncidentImpact.majorOutage);
    });

    test('impact setter stores value', () {
      final incident = Incident();
      incident.impact = IncidentImpact.partialOutage;
      expect(incident.getAttribute('impact'), 'partial_outage');
    });

    test('status getter returns enum', () {
      final incident = Incident();
      incident.setRawAttributes({'status': 'investigating'}, sync: true);
      expect(incident.status, IncidentStatus.investigating);
    });

    test('status setter stores value', () {
      final incident = Incident();
      incident.status = IncidentStatus.resolved;
      expect(incident.getAttribute('status'), 'resolved');
    });

    test('isAutoCreated getter returns bool', () {
      final incident = Incident();
      incident.setRawAttributes({'is_auto_created': true}, sync: true);
      expect(incident.isAutoCreated, true);
    });

    test('startedAt getter returns Carbon', () {
      final incident = Incident();
      incident.setRawAttributes({
        'started_at': '2024-01-15T10:30:00Z',
      }, sync: true);
      expect(incident.startedAt, isNotNull);
    });

    test('resolvedAt getter returns null when not set', () {
      final incident = Incident();
      incident.setRawAttributes({}, sync: true);
      expect(incident.resolvedAt, isNull);
    });

    test('monitorIds getter returns list of strings', () {
      final incident = Incident();
      incident.setRawAttributes({
        'monitor_ids': ['mon-1', 'mon-2'],
      }, sync: true);
      expect(incident.monitorIds, ['mon-1', 'mon-2']);
    });

    test('monitorIds setter stores list', () {
      final incident = Incident();
      incident.monitorIds = ['mon-1', 'mon-2'];
      expect(incident.getAttribute('monitor_ids'), ['mon-1', 'mon-2']);
    });

    test('isResolved computed property', () {
      final incident = Incident();
      incident.setRawAttributes({'status': 'resolved'}, sync: true);
      expect(incident.isResolved, true);

      incident.setRawAttributes({'status': 'investigating'}, sync: true);
      expect(incident.isResolved, false);
    });

    test('isActive computed property', () {
      final incident = Incident();
      incident.setRawAttributes({'status': 'investigating'}, sync: true);
      expect(incident.isActive, true);

      incident.setRawAttributes({'status': 'resolved'}, sync: true);
      expect(incident.isActive, false);
    });

    test('duration computed property', () {
      final incident = Incident();
      incident.setRawAttributes({
        'started_at': '2024-01-15T10:00:00Z',
        'resolved_at': '2024-01-15T11:00:00Z',
      }, sync: true);
      final duration = incident.duration;
      expect(duration.inHours, 1);
    });

    test('duration returns positive duration when not resolved', () {
      final incident = Incident();
      incident.setRawAttributes({
        'started_at': '2024-01-15T10:00:00Z',
      }, sync: true);
      expect(incident.duration.inSeconds, greaterThan(0));
    });
  });
}
