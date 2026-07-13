import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/app/mocks/status.dart';
import 'package:uptizm/app/models/incident.dart';

void main() {
  group('Incident model metadata', () {
    test('targets the incidents table and resource with a non-incrementing key', () {
      final Incident incident = Incident();

      expect(incident.table, 'incidents');
      expect(incident.resource, 'incidents');
      expect(incident.incrementing, isFalse);
    });

    test('exposes the StoreIncidentRequest fillable surface', () {
      final Incident incident = Incident();

      expect(
        incident.fillable,
        ['monitor_id', 'severity', 'title', 'message'],
      );
    });

    test('declares the bool and datetime casts the resource shape requires', () {
      final Incident incident = Incident();

      expect(incident.casts['ai_owned'], 'bool');
      expect(incident.casts['is_public'], 'bool');
      expect(incident.casts['autonomous'], 'bool');
      expect(incident.casts['created_at'], 'datetime');
      expect(incident.casts['updated_at'], 'datetime');
      expect(incident.casts['started_at'], 'datetime');
      expect(incident.casts['resolved_at'], 'datetime');
    });
  });

  group('Incident.fromMap', () {
    test('decodes a full IncidentResource payload', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'checkout-503',
        'team_id': 'team-1',
        'title': 'Checkout service returning 503s across all regions',
        'lifecycle': 'investigating',
        'severity': 'warn',
        'impact': 'critical',
        'signal_source': 'ai_anomaly',
        'ai_owned': true,
        'primary_monitor_id': 'checkout',
        'trigger_metric_key': 'status_code',
        'started_at': '2026-07-09T14:20:00.000Z',
        'resolved_at': null,
        'monitors': <Map<String, dynamic>>[
          <String, dynamic>{
            'monitor_id': 'checkout',
            'name': 'Checkout service',
            'component_status_at_start': 'down',
            'component_status_current': 'down',
          },
        ],
        'updates': <Map<String, dynamic>>[
          <String, dynamic>{
            'actor': 'human',
            'status': 'Investigating',
            'message': 'Rolling back the latest release now.',
            'is_public': true,
            'autonomous': false,
            'display_at': '2026-07-09T14:34:00.000Z',
          },
        ],
        'ai': <String, dynamic>{
          'trigger': 'AI anomaly',
          'confidence': 'high',
          'tldr': 'Origin-side fault.',
        },
      });

      expect(incident.id, 'checkout-503');
      expect(incident.exists, isTrue);
      expect(incident.teamId, 'team-1');
      expect(
        incident.title,
        'Checkout service returning 503s across all regions',
      );
      expect(incident.lifecycle, IncidentLifecycle.investigating);
      expect(incident.severity, IncidentSeverity.warning);
      expect(incident.impact, IncidentImpact.down);
      expect(incident.signalSource, SignalSource.anomaly);
      expect(incident.aiOwned, isTrue);
      expect(incident.primaryMonitorId, 'checkout');
      expect(incident.triggerMetricKey, 'status_code');
      expect(incident.monitorName, 'Checkout service');
      expect(incident.affectedCount, 1);
      expect(incident.affectedMonitors.single.name, 'Checkout service');
      expect(incident.affectedMonitors.single.statusAtStart, StatusKey.down);
      expect(
        incident.timeline.single.message,
        'Rolling back the latest release now.',
      );
      expect(incident.timeline.single.isPublic, isTrue);
      expect(incident.ai, isNotNull);
      expect(incident.ai!.trigger, 'AI anomaly');
      expect(incident.ai!.confidence, AiConfidence.high);
      expect(incident.ai!.tldr, 'Origin-side fault.');
    });

    test('resolves the primary monitor by monitor_id, not by list order', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'multi',
        'title': 'Two components affected',
        'primary_monitor_id': 'checkout',
        'started_at': '2026-07-09T14:20:00.000Z',
        'monitors': <Map<String, dynamic>>[
          <String, dynamic>{
            'monitor_id': 'marketing',
            'name': 'Marketing site',
            'component_status_at_start': 'degraded',
            'component_status_current': 'up',
          },
          <String, dynamic>{
            'monitor_id': 'checkout',
            'name': 'Checkout service',
            'component_status_at_start': 'down',
            'component_status_current': 'down',
          },
        ],
      });

      expect(incident.monitorName, 'Checkout service');
      expect(incident.affectedCount, 2);
      // The pivot order is preserved: the primary is resolved by monitor_id,
      // not by promoting the first affected monitor to the header.
      expect(incident.affectedMonitors.first.name, 'Marketing site');
    });

    test('unknown enum wire values fall back safely', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'unknown',
        'title': 'Unrecognized payload',
        'lifecycle': 'not_a_stage',
        'severity': 'not_a_severity',
        'impact': 'not_an_impact',
        'signal_source': 'not_a_source',
        'started_at': '2026-07-09T14:20:00.000Z',
      });

      expect(incident.lifecycle, IncidentLifecycle.detected);
      expect(incident.severity, IncidentSeverity.info);
      expect(incident.impact, IncidentImpact.info);
      expect(incident.signalSource, SignalSource.manual);
    });

    test('missing enum wire values fall back safely', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'bare',
        'title': 'Bare incident',
      });

      expect(incident.lifecycle, IncidentLifecycle.detected);
      expect(incident.severity, IncidentSeverity.info);
      expect(incident.impact, IncidentImpact.info);
      expect(incident.signalSource, SignalSource.manual);
    });

    test('coerces ai_owned from a truthy int via the bool cast', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'int-owned',
        'title': 'Integer owned flag',
        'ai_owned': 1,
      });

      expect(incident.aiOwned, isTrue);
    });

    test('decodes the AI confidence safely', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'ai',
        'title': 'AI',
        'started_at': '2026-07-09T14:20:00.000Z',
        'ai': <String, dynamic>{
          'trigger': 'AI anomaly',
          'confidence': 'not_a_confidence',
          'tldr': 'Unclear.',
        },
      });

      expect(incident.ai, isNotNull);
      expect(incident.ai!.confidence, AiConfidence.low);
    });

    test('leaves ai null when the map carries no ai sub-object', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'no-ai',
        'title': 'No AI',
        'started_at': '2026-07-09T14:20:00.000Z',
      });

      expect(incident.ai, isNull);
    });
  });

  group('Incident derived display accessors', () {
    test('startedAt reproduces the IncidentSummary relative meta line', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'started',
        'title': 'Started',
        'started_at': '2026-07-09T14:20:00.000Z',
        'resolved_at': null,
      });

      expect(incident.startedAt, startsWith('started '));
      expect(incident.startedAt, endsWith(' ago'));
    });

    test('startedAt reads as resolved when resolved_at is set', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'resolved',
        'title': 'Resolved',
        'started_at': '2026-07-09T14:20:00.000Z',
        'resolved_at': '2026-07-09T15:20:00.000Z',
      });

      expect(incident.startedAt, startsWith('resolved '));
      expect(incident.startedAt, endsWith(' ago'));
    });

    test('duration reproduces the IncidentSummary elapsed format', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'duration',
        'title': 'Duration',
        'started_at': '2026-07-09T14:20:00.000Z',
        'resolved_at': '2026-07-09T14:34:00.000Z',
      });

      expect(incident.duration, '14m');
    });
  });

  group('Public wire-bridge and formatting helpers', () {
    test('the six wire bridges are importable and decode correctly', () {
      expect(
        lifecycleFromWire('investigating'),
        IncidentLifecycle.investigating,
      );
      expect(severityFromWire('warn'), IncidentSeverity.warning);
      expect(signalSourceFromWire('ai_anomaly'), SignalSource.anomaly);
      expect(impactFromWire('critical'), IncidentImpact.down);
      expect(aiConfidenceFromWire('high'), AiConfidence.high);
      expect(timelineActorFromWire('human'), TimelineActor.human);
    });

    test('the three formatting helpers are importable', () {
      final DateTime started = DateTime.utc(2026, 7, 9, 14, 20);
      final DateTime resolved = DateTime.utc(2026, 7, 9, 14, 34);

      expect(formatDuration(started, resolved), '14m');
      expect(formatRelativeMeta(started, null), startsWith('started '));
      expect(
        RegExp(r'^\d{2}:\d{2}$').hasMatch(formatHourMinute(
          '2026-07-09T14:34:00.000Z',
        )),
        isTrue,
      );
    });
  });

  group('Incident static persistence helpers', () {
    test('find degrades to null when no remote is reachable', () async {
      expect(await Incident.find('checkout-503'), isNull);
    });

    test('all degrades to an empty list when no remote is reachable', () async {
      expect(await Incident.all(), isEmpty);
    });
  });
}
