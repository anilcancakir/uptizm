import 'package:flutter/widgets.dart' show Locale;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart' show TranslationLoader, Translator;
import 'package:uptizm/app/enums/ai_confidence.dart' show AiConfidence, aiConfidenceFromWire;
import 'package:uptizm/app/enums/incident_impact.dart' show IncidentImpact, impactFromWire;
import 'package:uptizm/app/enums/incident_lifecycle.dart' show IncidentLifecycle, lifecycleFromWire;
import 'package:uptizm/app/enums/incident_severity.dart' show IncidentSeverity, severityFromWire;
import 'package:uptizm/app/enums/signal_source.dart' show SignalSource, signalSourceFromWire;
import 'package:uptizm/app/enums/timeline_actor.dart' show TimelineActor, timelineActorFromWire;
import 'package:uptizm/app/support/formatters.dart'
    show formatDuration, formatHourMinute, formatRelativeAge, formatRelativeMeta;
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/app/enums/incident_title_key.dart'
    show IncidentTitleKey, incidentTitleKeyFromWire;
import 'package:uptizm/app/models/incident.dart';

import '../../support/bundled_lang.dart';

/// Serves the SHIPPED Turkish catalogue, which is where both localized things
/// this file asserts come from: the two duration units [formatDuration] reads,
/// and the six `uptizm.incidents.title_*` sentences [Incident.displayTitle]
/// renders.
///
/// Turkish rather than English on purpose. With the English units the duration
/// assertions would pass just as well against a formatter that hardcoded them, and
/// with an inline map the headline assertions would agree with the test author
/// instead of the product: `lang_parity_test.dart` compares key SETS and never
/// values, so a Turkish entry left in English is invisible to every gate except an
/// assertion that reads the shipped file.
class _BundledTurkishLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async =>
      readBundledLang('tr');
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_BundledTurkishLangLoader());
    await Translator.instance.setLocale(const Locale('tr'));
  });

  group('Incident model metadata', () {
    test('targets the incidents table and resource with a non-incrementing key', () {
      final Incident incident = Incident();

      expect(incident.table, 'incidents');
      expect(incident.resource, 'incidents');
      expect(incident.incrementing, isFalse);
    });

    test('exposes the StoreIncidentRequest fillable surface', () {
      final Incident incident = Incident();

      // The WRITE contract, not the column list: `message` is the opening
      // timeline note and `notify`/`impact` are request-only decisions. A
      // field missing here is stripped before the request leaves the client,
      // with no error anywhere, which is how this codebase has lost writes
      // before.
      expect(
        incident.fillable,
        ['monitor_id', 'severity', 'title', 'message', 'notify', 'impact'],
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
      expect(incident.casts['postmortem_published_at'], 'datetime');
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
            'author': 'Ada Byron',
            'status': 'investigating',
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
      // This payload carries no `title_key`, which is the backend saying a human
      // wrote the headline: there is nothing to render from, so the display
      // headline IS the stored column. The structured cases live in their own
      // group below.
      expect(incident.titleKey, isNull);
      expect(incident.titleParams, isEmpty);
      expect(incident.displayTitle, incident.title);
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
      // The resource DOES carry `author`; it is the real persisted attribution
      // the backend stamped, so the timeline surfaces it rather than dropping it.
      expect(incident.timeline.single.author, 'Ada Byron');
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

  group('Incident.displayTitle', () {
    /// An incident carrying the wire shape `IncidentResource` emits for a
    /// composed title.
    Incident composed(String? key, Object? params) => Incident.fromMap({
      'id': 'composed',
      'title': 'the stored English',
      'title_key': ?key,
      'title_params': ?params,
      'started_at': '2026-07-09T14:20:00.000Z',
    });

    test('renders the composed sentence from the shipped Turkish catalogue', () {
      final Incident incident = composed('incidents.monitor_down', {
        'monitor': 'checkout',
      });

      expect(incident.titleKey, IncidentTitleKey.monitorDown);
      expect(incident.titleParams, {'monitor': 'checkout'});
      // Spelled out rather than read back through `trans()`: the loader above
      // serves the shipped file, so this line is what fails when a Turkish value
      // is edited into English.
      expect(incident.displayTitle, 'checkout kesintide');
      expect(incident.displayTitle, isNot(incident.title));
    });

    test('a null title_key renders the stored title, whatever it says', () {
      final Incident incident = composed(null, null);

      expect(incident.titleKey, isNull);
      expect(incident.displayTitle, 'the stored English');
    });

    test('an unrecognised title_key renders the stored title, never a raw key', () {
      // The state a client older than the backend lands in. Falling back to the
      // column beats guessing a member: the column holds a real sentence the
      // backend composed, and a guessed member would render a DIFFERENT sentence
      // out of parameters that may not belong to it.
      final Incident incident = composed('incidents.some_seventh_kind', {
        'monitor': 'checkout',
      });

      expect(incidentTitleKeyFromWire('incidents.some_seventh_kind'), isNull);
      expect(incident.displayTitle, 'the stored English');
      expect(incident.displayTitle, isNot(contains('incidents.')));
    });

    test('the SSL day count picks the catalogue entry, not the wire value', () {
      // `days` arrives as a jsonb NUMBER here, which is how PostgreSQL round-trips
      // it, and the model coerces it to a string before the count is read.
      final Incident incident = composed('incidents.ssl_expiring', {
        'monitor': 'api',
        'days': 1,
      });

      expect(incident.titleKey, IncidentTitleKey.sslExpiring);
      expect(incident.titleParams, {'monitor': 'api', 'days': '1'});
      expect(incident.displayTitle, 'api sertifikası 1 gün içinde doluyor');

      // The suffix is chosen client-side from the same `days` value the backend
      // resolver reads, and it never crosses the wire: the enum has a member for
      // the BARE key only. Turkish keeps the noun singular after a cardinal, so
      // the two entries hold one sentence and only the KEY choice is observable.
      expect(
        IncidentTitleKey.sslExpiring.catalogueKey({'days': '1'}),
        'uptizm.incidents.title_ssl_expiring_one',
      );
      expect(
        IncidentTitleKey.sslExpiring.catalogueKey({'days': '14'}),
        'uptizm.incidents.title_ssl_expiring_other',
      );
    });

    test('every one of the six keys renders a real sentence, not its own key', () {
      const Map<String, Map<String, Object>> everyKey = {
        'incidents.monitor_down': {'monitor': 'checkout'},
        'incidents.metric_warn_bound': {'metric': 'CPU'},
        'incidents.metric_critical_bound': {'metric': 'CPU'},
        'incidents.metric_string_value': {'metric': 'Redis state', 'value': 'DOWN'},
        'incidents.ssl_expiring': {'monitor': 'api', 'days': 14},
        'incidents.ai_anomaly': {'monitor': 'checkout'},
      };

      everyKey.forEach((String key, Map<String, Object> params) {
        final String rendered = composed(key, params).displayTitle;

        // A catalogue entry missing from `tr.json` renders its own dotted key
        // (magic's `trans()` answers the key for an unknown one), and an
        // unreplaced placeholder keeps its colon. Both are what a key added on
        // one half only looks like on screen.
        expect(rendered, isNot(contains('uptizm.')), reason: '$key rendered a raw key');
        expect(rendered, isNot(contains(':')), reason: '$key left a placeholder');
        expect(rendered, isNot('the stored English'), reason: '$key fell back');
      });
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

      // Turkish closes with the verb, so the shape assertion has to close with
      // it too; asserting an English prefix here is what hid the untranslated
      // clause until an operator read it on a Turkish dashboard.
      expect(incident.startedAt, endsWith(' başladı'));
      expect(incident.startedAt, contains(' önce '));
    });

    test('startedAt reads as resolved when resolved_at is set', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'resolved',
        'title': 'Resolved',
        'started_at': '2026-07-09T14:20:00.000Z',
        'resolved_at': '2026-07-09T15:20:00.000Z',
      });

      expect(incident.startedAt, endsWith(' çözüldü'));
      expect(incident.startedAt, contains(' önce '));
    });

    test('duration reproduces the IncidentSummary elapsed format', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'duration',
        'title': 'Duration',
        'started_at': '2026-07-09T14:20:00.000Z',
        'resolved_at': '2026-07-09T14:34:00.000Z',
      });

      expect(incident.duration, '14dk');
    });
  });

  group('Incident assignee', () {
    test('decodes the resource assignee sub-object', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'assigned',
        'title': 'Assigned',
        'started_at': '2026-07-09T14:20:00.000Z',
        'assignee': <String, dynamic>{'id': 'u2', 'name': 'Ravi Shah'},
      });

      expect(incident.assigneeId, 'u2');
      expect(incident.assigneeName, 'Ravi Shah');
    });

    test('reads as unassigned for a null, absent, or blank assignee', () {
      Incident withAssignee(Object? assignee) => Incident.fromMap({
        'id': 'x',
        'title': 'X',
        'started_at': '2026-07-09T14:20:00.000Z',
        'assignee': ?assignee,
      });

      expect(withAssignee(null).assigneeId, isNull);
      expect(withAssignee(null).assigneeName, isNull);
      expect(
        withAssignee(<String, dynamic>{'id': '', 'name': '  '}).assigneeId,
        isNull,
      );
      expect(
        withAssignee(<String, dynamic>{'id': 'u3', 'name': '  '}).assigneeName,
        isNull,
      );
    });
  });

  group('Incident postmortem', () {
    test('a body with a publication stamp reads as published', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'pm',
        'title': 'PM',
        'started_at': '2026-07-09T14:20:00.000Z',
        'postmortem_body': 'Root cause: pool starvation.',
        'postmortem_published_at': '2026-07-09T16:00:00.000Z',
      });

      expect(incident.postmortemBody, 'Root cause: pool starvation.');
      expect(incident.postmortemPublishedAt, isNotNull);
      expect(incident.postmortemIsPublished, isTrue);
    });

    test('a body without a stamp is an internal draft, never published', () {
      final Incident incident = Incident.fromMap(<String, dynamic>{
        'id': 'pm-draft',
        'title': 'PM draft',
        'started_at': '2026-07-09T14:20:00.000Z',
        'postmortem_body': 'Internal draft.',
      });

      expect(incident.postmortemBody, 'Internal draft.');
      expect(incident.postmortemPublishedAt, isNull);
      expect(incident.postmortemIsPublished, isFalse);
    });

    test('a blank or absent body reads as no postmortem at all', () {
      final Incident blank = Incident.fromMap(<String, dynamic>{
        'id': 'pm-blank',
        'title': 'PM blank',
        'started_at': '2026-07-09T14:20:00.000Z',
        'postmortem_body': '   ',
        'postmortem_published_at': '2026-07-09T16:00:00.000Z',
      });
      final Incident absent = Incident.fromMap(<String, dynamic>{
        'id': 'pm-absent',
        'title': 'PM absent',
        'started_at': '2026-07-09T14:20:00.000Z',
      });

      expect(blank.postmortemBody, isNull);
      expect(blank.postmortemIsPublished, isFalse);
      expect(absent.postmortemBody, isNull);
      expect(absent.postmortemIsPublished, isFalse);
    });
  });

  group('Incident acknowledgement', () {
    /// Builds an incident whose timeline is [updates], in wire order (the
    /// backend returns `updates` ordered by `display_at` ascending).
    Incident withTimeline(List<Map<String, dynamic>> updates) {
      return Incident.fromMap(<String, dynamic>{
        'id': 'ack',
        'title': 'Ack',
        'started_at': '2026-07-09T14:20:00.000Z',
        'updates': updates,
      });
    }

    test('derives from the persisted human investigating entry', () {
      final Incident incident = withTimeline([
        <String, dynamic>{
          'actor': 'ai',
          'author': 'Uptizm AI',
          'status': 'detected',
          'message': 'Anomaly band crossed.',
          'display_at': '2026-07-09T14:32:00.000Z',
        },
        <String, dynamic>{
          'actor': 'human',
          'author': 'Demo',
          'status': 'investigating',
          'message': 'Incident acknowledged; investigation in progress.',
          'display_at': '2026-07-09T14:33:00.000Z',
        },
      ]);

      expect(incident.acknowledgement, isNotNull);
      expect(incident.acknowledgement!.by, 'Demo');
      expect(
        incident.acknowledgement!.at,
        formatHourMinute('2026-07-09T14:33:00.000Z'),
      );
    });

    test('takes the EARLIEST such entry, so a reopen cannot shadow it', () {
      final Incident incident = withTimeline([
        <String, dynamic>{
          'actor': 'human',
          'author': 'Demo',
          'status': 'investigating',
          'message': 'Incident acknowledged; investigation in progress.',
          'display_at': '2026-07-09T14:33:00.000Z',
        },
        <String, dynamic>{
          'actor': 'human',
          'author': 'Someone Else',
          'status': 'investigating',
          'message': 'Incident reopened by operator.',
          'display_at': '2026-07-09T18:00:00.000Z',
        },
      ]);

      expect(incident.acknowledgement!.by, 'Demo');
    });

    test('is null with no human investigating entry, and never invents an '
        'author', () {
      expect(withTimeline(const []).acknowledgement, isNull);
      expect(
        withTimeline([
          <String, dynamic>{
            'actor': 'system',
            'status': 'investigating',
            'message': 'Auto-escalated.',
            'display_at': '2026-07-09T14:33:00.000Z',
          },
        ]).acknowledgement,
        isNull,
        reason: 'A system entry has no acknowledging human',
      );
      expect(
        withTimeline([
          <String, dynamic>{
            'actor': 'human',
            'author': '  ',
            'status': 'investigating',
            'message': 'Unattributed.',
            'display_at': '2026-07-09T14:33:00.000Z',
          },
        ]).acknowledgement,
        isNull,
        reason: 'A blank author is not a person; nothing is substituted',
      );
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

      expect(formatDuration(started, resolved), '14dk');
      expect(
        RegExp(r'^\d{2}:\d{2}$').hasMatch(formatHourMinute(
          '2026-07-09T14:34:00.000Z',
        )),
        isTrue,
      );
    });

    test('the relative age reads in Turkish at every granularity', () {
      final DateTime now = DateTime.now();

      expect(formatRelativeAge(now.subtract(const Duration(seconds: 8))),
          '8 sn önce');
      expect(formatRelativeAge(now.subtract(const Duration(minutes: 14))),
          '14 dk önce');
      expect(
          formatRelativeAge(now.subtract(const Duration(hours: 2))), '2 sa önce');
      expect(
          formatRelativeAge(now.subtract(const Duration(days: 5))), '5 gün önce');
    });

    test('the meta line puts the Turkish verb last, not first', () {
      // The whole reason the clause is a catalogue entry rather than a prefix
      // concatenated onto the age: English leads with the verb and Turkish
      // closes with it, so a `'$verb $age'` would read "başladı 14 dk önce".
      final DateTime now = DateTime.now();
      final DateTime started = now.subtract(const Duration(minutes: 14));

      expect(formatRelativeMeta(started, null), '14 dk önce başladı');
      expect(
        formatRelativeMeta(started, now.subtract(const Duration(hours: 2))),
        '2 sa önce çözüldü',
      );
    });

    test('startedAge states the age with no lifecycle verb attached', () {
      // What the AI inbox renders. It used to reach this by regex-stripping the
      // English verb off `startedAt`, which matches nothing once translated.
      final Incident incident = Incident()
        ..setAttribute(
          'started_at',
          DateTime.now().subtract(const Duration(minutes: 14)).toIso8601String(),
        );

      expect(incident.startedAge, '14 dk önce');
      expect(incident.startedAge, isNot(contains('başladı')));
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
