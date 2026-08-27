import 'package:uptizm/app/enums/incident_impact.dart' show IncidentImpact;
import 'package:uptizm/app/enums/incident_lifecycle.dart' show IncidentLifecycle;
import 'package:uptizm/app/enums/incident_severity.dart' show IncidentSeverity;
import 'package:uptizm/app/enums/signal_source.dart' show SignalSource;
import 'package:uptizm/app/support/incident_types.dart'
    show IncidentSummary, TimelineEntry;
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/app/models/incident.dart';

/// Projects a design-lab [IncidentSummary] fixture into an [Incident] ORM
/// model, reconstructing the `IncidentResource`-shaped wire map the model
/// decodes from the DTO's computed accessors.
///
/// The incident-facing controllers, views, and the [IncidentCard] /
/// [AiInboxItem] components migrated their surface from the [IncidentSummary]
/// DTO to the [Incident] ORM model, so widget tests now need `Incident`
/// instances. This bridges the still-present design-lab [incidents] fixtures
/// onto the model shape (through [Incident.fromMap], the same decode path the
/// live list uses) without duplicating the five representative incidents across
/// every test that renders one.
///
/// The wire-value inverses (`down` -> `critical`, `warning` -> `warn`,
/// `threshold` -> `user_threshold`, ...) round-trip the DTO enums back to the
/// backend vocabulary the model's wire-bridge helpers decode. The design-lab
/// DTO's `assignee` / `acknowledged` fields are deliberately NOT projected: the
/// live model derives ownership from the resource's `assignee` object and the
/// acknowledgement from the persisted timeline, so a projected fixture must not
/// hand it either through a side door.
Incident asIncident(IncidentSummary summary) {
  final bool resolved = summary.lifecycle == IncidentLifecycle.resolved;

  final List<Map<String, dynamic>> monitors = <Map<String, dynamic>>[
    for (int i = 0; i < summary.affectedMonitors.length; i++)
      <String, dynamic>{
        'monitor_id': 'm$i',
        'name': summary.affectedMonitors[i].name,
        'component_status_at_start': summary.affectedMonitors[i].statusAtStart.name,
        'component_status_current': summary.affectedMonitors[i].statusCurrent.name,
      },
  ];

  // Resolve the primary monitor id so [Incident.monitorName] reads the same
  // name the DTO's `monitorName` carried.
  final int primaryIndex = summary.affectedMonitors.indexWhere(
    (m) => m.name == summary.monitorName,
  );
  final String? primaryMonitorId = primaryIndex >= 0
      ? 'm$primaryIndex'
      : (monitors.isEmpty ? null : 'm0');

  return Incident.fromMap(<String, dynamic>{
    'id': summary.id,
    'title': summary.title,
    // The structured half of the headline, projected exactly as
    // `IncidentResource` emits it: a null key for an authored title, and an empty
    // JSON LIST rather than a null or an empty object for "no parameters", because
    // PHP's `[]` encodes as a list. That shape is why `Incident.titleParams`
    // absorbs a non-map, and projecting the real one is what exercises it.
    'title_key': summary.titleKey,
    'title_params': summary.titleParams.isEmpty
        ? const <Object?>[]
        : summary.titleParams,
    'impact': _impactToWire(summary.impact),
    'severity': _severityToWire(summary.severity),
    'signal_source': _signalSourceToWire(summary.signalSource),
    'lifecycle': summary.lifecycle.name,
    'ai_owned': summary.aiOwned,
    'started_at': '2026-07-11T14:00:00Z',
    if (resolved) 'resolved_at': '2026-07-11T15:00:00Z',
    'primary_monitor_id': primaryMonitorId,
    'monitors': monitors,
    'updates': <Map<String, dynamic>>[
      for (final TimelineEntry e in summary.timeline)
        <String, dynamic>{
          'actor': e.actor.name,
          // The resource carries `author`, so the projection does too: a
          // backend-decoded entry surfaces its real persisted attribution.
          'author': e.author,
          'status': e.status,
          'message': e.message,
          'is_public': e.isPublic,
          'autonomous': e.autonomous,
          'created_at': '2026-07-11T14:30:00Z',
        },
    ],
    if (summary.ai != null)
      'ai': <String, dynamic>{
        'trigger': summary.ai!.trigger,
        'confidence': summary.ai!.confidence.name,
        'tldr': summary.ai!.tldr,
      },
  });
}

/// The design-lab [incidents] fixture projected into [Incident] models, for
/// rendering the incident components/views in widget tests.
List<Incident> get incidentFixtures => incidents.map(asIncident).toList();

/// Finds a projected [Incident] fixture by [id], or `null` when none matches.
Incident? findIncidentFixture(String id) {
  for (final Incident incident in incidentFixtures) {
    if (incident.id == id) return incident;
  }
  return null;
}

/// Maps a [IncidentImpact] back to its backend `impact` wire value
/// (`impactFromWire`'s inverse: `critical`/`major` -> down, `minor` ->
/// degraded, `none` -> info).
String _impactToWire(IncidentImpact impact) {
  return switch (impact) {
    IncidentImpact.down => 'critical',
    IncidentImpact.degraded => 'minor',
    IncidentImpact.info => 'none',
  };
}

/// Maps a [IncidentSeverity] back to its backend `severity` wire value (the
/// backend spells `warning` as `warn`).
String _severityToWire(IncidentSeverity severity) {
  return switch (severity) {
    IncidentSeverity.critical => 'critical',
    IncidentSeverity.warning => 'warn',
    IncidentSeverity.info => 'info',
  };
}

/// Maps a [SignalSource] back to its backend `signal_source` wire value.
String _signalSourceToWire(SignalSource source) {
  return switch (source) {
    SignalSource.threshold => 'user_threshold',
    SignalSource.anomaly => 'ai_anomaly',
    SignalSource.manual => 'manual',
  };
}

/// The fixture roster in the wire shape `GET /incidents` answers with.
///
/// Exists because the roster now PAGES: a screen resolves its row out of what
/// the index returned, so a test that stubs an empty index and then looks a
/// fixture up by id is asking the view for a row the controller was never
/// given. Round-tripping the fixtures keeps the stub and the lookup agreeing.
List<Map<String, dynamic>> incidentIndexPayload() =>
    incidentFixtures.map((Incident incident) => incident.toMap()).toList();
