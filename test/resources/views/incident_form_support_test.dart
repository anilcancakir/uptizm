import 'package:flutter_test/flutter_test.dart';

import 'package:uptizm/app/mocks/billing.dart';
import 'package:uptizm/app/mocks/incidents.dart' as mocks;
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/resources/views/incident_form_support.dart';

void main() {
  // ---------------------------------------------------------------------------
  // severityFromConfidence
  // ---------------------------------------------------------------------------

  group('severityFromConfidence', () {
    test('maps high confidence to critical', () {
      expect(severityFromConfidence[mocks.AiConfidence.high], 'critical');
    });

    test('maps medium confidence to warning', () {
      expect(severityFromConfidence[mocks.AiConfidence.medium], 'warning');
    });

    test('maps low confidence to info', () {
      expect(severityFromConfidence[mocks.AiConfidence.low], 'info');
    });
  });

  // ---------------------------------------------------------------------------
  // draftUpdate / postmortemDraft
  // ---------------------------------------------------------------------------

  group('draftUpdate', () {
    test('produces a non-empty string referencing the monitor name', () {
      final mocks.IncidentSummary incident = mocks.findIncident(
        'checkout-503',
      )!;
      final String draft = draftUpdate(incident);

      expect(draft, isNotEmpty);
      expect(draft, contains(incident.monitorName));
    });

    test('branches on the resolved lifecycle for a resolved incident', () {
      final mocks.IncidentSummary incident = mocks.findIncident(
        'eu-packet-loss',
      )!;
      final String draft = draftUpdate(incident);

      expect(draft, isNotEmpty);
      expect(draft, contains(incident.monitorName));
      expect(draft.toLowerCase(), contains('resolved'));
    });
  });

  group('postmortemDraft', () {
    test('produces a non-empty string referencing the duration', () {
      final mocks.IncidentSummary incident = mocks.findIncident(
        'eu-packet-loss',
      )!;
      final String draft = postmortemDraft(incident);

      expect(draft, isNotEmpty);
      expect(draft, contains(incident.duration));
      expect(draft, contains(incident.title));
    });
  });

  // ---------------------------------------------------------------------------
  // monitorsToRegions
  // ---------------------------------------------------------------------------

  group('monitorsToRegions', () {
    test('maps every monitor, preserving label and value', () {
      final regions = monitorsToRegions();

      expect(regions.length, monitors.length);
      for (var i = 0; i < monitors.length; i++) {
        expect(regions[i].label, monitors[i].name);
        expect(regions[i].value, monitors[i].id);
      }
    });
  });

  // ---------------------------------------------------------------------------
  // toComponentTimeline
  // ---------------------------------------------------------------------------

  group('toComponentTimeline', () {
    test(
      'preserves entry count, isPublic, actor, and message across the mapping',
      () {
        final mocks.IncidentSummary incident = mocks.findIncident(
          'checkout-503',
        )!;
        final mapped = toComponentTimeline(incident.timeline);

        expect(mapped.length, incident.timeline.length);
        for (var i = 0; i < incident.timeline.length; i++) {
          final mocks.TimelineEntry source = incident.timeline[i];
          final mapped_ = mapped[i];

          expect(mapped_.isPublic, source.isPublic);
          expect(mapped_.actor.name, source.actor.name);
          expect(mapped_.message, source.message);
          expect(mapped_.status, source.status);
          expect(mapped_.time, source.time);
          expect(mapped_.author, source.author);
          expect(mapped_.autonomous, source.autonomous);
        }
      },
    );
  });

  // ---------------------------------------------------------------------------
  // planForAiAnalysis
  // ---------------------------------------------------------------------------

  group('planForAiAnalysis', () {
    test('returns the cheapest plan whose ai capability covers analysis', () {
      final Plan plan = planForAiAnalysis();

      expect(plan.limits.ai.index, greaterThanOrEqualTo(AiLevel.analysis.index));

      // The plans list is ordered cheapest-first; no earlier plan qualifies.
      final int planIndex = plans.indexOf(plan);
      for (var i = 0; i < planIndex; i++) {
        expect(plans[i].limits.ai.index, lessThan(AiLevel.analysis.index));
      }
    });
  });
}
