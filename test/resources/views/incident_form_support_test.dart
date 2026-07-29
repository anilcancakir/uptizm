import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/ai_confidence.dart' as mocks;
import 'package:uptizm/app/support/incident_types.dart'
    as mocks
    show IncidentSummary, TimelineEntry;
import 'package:uptizm/app/mocks/incidents.dart' as mocks;
import 'package:uptizm/app/models/incident.dart';
import 'package:uptizm/resources/views/incidents/incident_form_support.dart';

import '../../support/incident_fixtures.dart';

/// Feeds the incident draft templates so [trans] substitutes the incident's
/// name/title/duration into real English prose instead of returning raw keys.
class _DraftLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {
    'uptizm.incidents.draft_resolved':
        'This incident is resolved. :name is back to normal across all regions '
        'and checks are passing again. Thanks for your patience.',
    'uptizm.incidents.draft_maintenance':
        'Scheduled maintenance on :name is underway.',
    'uptizm.incidents.draft_investigating':
        "We're investigating :what affecting :name. Uptizm's checks are showing "
        ":signal. We'll share another update within 30 minutes.",
    'uptizm.incidents.draft_what_down': 'a major outage',
    'uptizm.incidents.draft_what_degraded': 'degraded performance',
    'uptizm.incidents.draft_what_info': 'a service issue',
    'uptizm.incidents.draft_signal_errors': 'errors across regions',
    'uptizm.incidents.draft_signal_latency': 'elevated response times',
    'uptizm.incidents.postmortem':
        ':title lasted :duration and affected :count :monitorWord. Uptizm first '
        'detected it via :signal, then saw checks recover before it was '
        'resolved.',
    'uptizm.incidents.postmortem_monitor_one': 'monitor',
    'uptizm.incidents.postmortem_monitor_other': 'monitors',
  };
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_DraftLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });
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
      final Incident incident = findIncidentFixture('checkout-503')!;
      final String draft = draftUpdate(incident);

      expect(draft, isNotEmpty);
      expect(draft, contains(incident.monitorName));
    });

    test('branches on the resolved lifecycle for a resolved incident', () {
      final Incident incident = findIncidentFixture('eu-packet-loss')!;
      final String draft = draftUpdate(incident);

      expect(draft, isNotEmpty);
      expect(draft, contains(incident.monitorName));
      expect(draft.toLowerCase(), contains('resolved'));
    });
  });

  group('postmortemDraft', () {
    test('produces a non-empty string referencing the duration', () {
      final Incident incident = findIncidentFixture('eu-packet-loss')!;
      final String draft = postmortemDraft(incident);

      expect(draft, isNotEmpty);
      expect(draft, contains(incident.duration));
      expect(draft, contains(incident.title));
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
}
