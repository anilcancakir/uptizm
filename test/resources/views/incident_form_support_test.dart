import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/ai_confidence.dart' as mocks;
import 'package:uptizm/app/enums/timeline_actor.dart' as mocks;
import 'package:uptizm/app/support/incident_types.dart'
    as mocks
    show IncidentSummary, TimelineEntry;
import 'package:uptizm/app/mocks/incidents.dart' as mocks;
import 'package:uptizm/app/models/incident.dart';
import 'package:uptizm/resources/views/incidents/incident_form_support.dart';
import 'package:uptizm/ui/components/incident_timeline/index.dart'
    show TimelineActor;

import '../../support/bundled_lang.dart';
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
  };
}

/// Feeds [trans] from the SHIPPED `assets/lang/<languageCode>.json`, flattened
/// the way `JsonAssetLoader` does at runtime.
///
/// The postmortem assertions below are about the sentence an operator reads, so
/// an inline map would have them agree with a fixture instead of with the
/// product: the previous English-only map is precisely why an ungrammatical
/// Turkish postmortem shipped past a green suite, and `lang_parity_test.dart`
/// compares key SETS and never values. The suite runs with the repo root as its
/// cwd (that same parity test reads `assets/lang/en.json` off disk), so the
/// bundled asset is readable from here.
class _BundledLangLoader implements TranslationLoader {
  const _BundledLangLoader();

  @override
  Future<Map<String, dynamic>> load(Locale locale) async =>
      readBundledLang(locale.languageCode);
}

/// A resolved incident lasting exactly one minute and affecting [monitors]
/// monitors.
///
/// Built through [Incident.fromMap] instead of from the shared fixtures because
/// every fixture spans a full hour, and the sub-hour branch of [formatDuration]
/// is the one whose unit an operator reads as "1dk" or "1m".
Incident _postmortemIncident({required int monitors}) {
  return Incident.fromMap(<String, dynamic>{
    'id': 'pm-1',
    'title': 'Checkout service returning 503s',
    'lifecycle': 'resolved',
    'signal_source': 'user_threshold',
    'started_at': '2026-07-09T14:20:00.000Z',
    'resolved_at': '2026-07-09T14:21:00.000Z',
    'monitors': <Map<String, dynamic>>[
      for (int i = 0; i < monitors; i++)
        <String, dynamic>{'monitor_id': 'm$i', 'name': 'checkout-$i'},
    ],
  });
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
    setUp(() {
      Translator.instance.setLoader(const _BundledLangLoader());
    });

    test('reads as publishable Turkish, from the shipped catalogue', () async {
      await Translator.instance.setLocale(const Locale('tr'));
      final Incident incident = _postmortemIncident(monitors: 1);

      final String draft = postmortemDraft(incident);

      // Passive, so the count's noun is a nominative subject needing no case
      // suffix. The active `etkiledi` demands an accusative (`izleyiciyi`) that
      // no Dart-side suffixing may supply.
      expect(draft, contains('1 izleyici etkilendi'));
      expect(draft, isNot(contains('etkiledi')));
      // The draft renders under the incident's own heading, so repeating an
      // English title inside a Turkish sentence bought nothing but a broken
      // grammatical subject.
      expect(draft, isNot(contains(incident.title)));
      // The unit comes from the catalogue: an English `1m` here is the same
      // defect class as the English title was.
      expect(draft, contains('1dk'));
      expect(draft, isNot(contains('1m')));
    });

    test('keeps the English monitor plural pair working', () async {
      await Translator.instance.setLocale(const Locale('en'));

      expect(
        postmortemDraft(_postmortemIncident(monitors: 1)),
        contains('affected 1 monitor'),
      );
      expect(
        postmortemDraft(_postmortemIncident(monitors: 3)),
        contains('affected 3 monitors'),
      );
    });

    test('states the duration and the detecting signal', () async {
      await Translator.instance.setLocale(const Locale('en'));
      final Incident incident = _postmortemIncident(monitors: 1);

      final String draft = postmortemDraft(incident);

      expect(draft, isNotEmpty);
      expect(draft, contains(incident.duration));
      expect(draft, contains(incident.signalSource.label.toLowerCase()));
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

    // Which actors the test above exercises depends on what the
    // `checkout-503` fixture happens to contain, and that fixture carries only
    // `ai` and `human`: the `system` case was never covered by anything. This
    // one names all three members and spells the expected component member
    // literally, so it stays complete whatever the fixtures do later.
    test('maps every domain actor onto its component counterpart', () {
      const List<mocks.TimelineActor> sources = [
        mocks.TimelineActor.ai,
        mocks.TimelineActor.human,
        mocks.TimelineActor.system,
      ];

      final mapped = toComponentTimeline([
        for (final mocks.TimelineActor actor in sources)
          mocks.TimelineEntry(
            actor: actor,
            status: 'Investigating',
            message: 'body',
            time: '14:34',
          ),
      ]);

      expect(mapped.map((entry) => entry.actor).toList(), [
        TimelineActor.ai,
        TimelineActor.human,
        TimelineActor.system,
      ]);
    });
  });
}
