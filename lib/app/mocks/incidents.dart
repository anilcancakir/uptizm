import '../enums/ai_confidence.dart' show AiConfidence;
import '../enums/incident_impact.dart' show IncidentImpact;
import '../enums/incident_lifecycle.dart' show IncidentLifecycle;
import '../enums/incident_severity.dart' show IncidentSeverity;
import '../enums/signal_source.dart' show SignalSource;
import '../enums/status_key.dart' show StatusKey;
import '../enums/timeline_actor.dart' show TimelineActor;
import '../support/incident_types.dart'
    show
        AffectedMonitor,
        AiEvidence,
        AiSimilarIncident,
        AiSuggestedAction,
        IncidentAi,
        IncidentSummary,
        TimelineEntry;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/// Design-lab incident fixtures. Five incidents covering all lifecycle stages,
/// signal sources, severities, and AI/manual ownership states.
///
/// The first one carries a structured title (a `title_key` plus its parameters)
/// and the other four carry none, because both are real states and each has to be
/// rendered somewhere: an automatically opened incident is a catalogue key the
/// client renders in the app's language, and an operator-authored one is text a
/// human already chose the language of. A fixture set that was all one or all the
/// other would leave half the headline path untested.
const List<IncidentSummary> incidents = [
  // 1. Active AI-owned outage. The one structured title in this set: the anomaly
  //    detector opened it, so its headline is `incidents.ai_anomaly` plus the
  //    monitor name, and the English below is exactly what the backend's own `en`
  //    catalogue composes for that pair. Keeping the two in step is the point: a
  //    stored sentence that no key could produce would be a state the backend
  //    cannot write.
  IncidentSummary(
    id: 'checkout-503',
    title: 'Anomaly detected on Checkout service',
    titleKey: 'incidents.ai_anomaly',
    titleParams: {'monitor': 'Checkout service'},
    impact: IncidentImpact.down,
    severity: IncidentSeverity.critical,
    signalSource: SignalSource.anomaly,
    lifecycle: IncidentLifecycle.investigating,
    startedAt: 'started 14m ago',
    duration: '14m',
    affectedCount: 1,
    aiOwned: true,
    monitorName: 'Checkout service',
    affectedMonitors: [
      AffectedMonitor(
        name: 'Checkout service',
        statusAtStart: StatusKey.down,
        statusCurrent: StatusKey.down,
      ),
    ],
    timeline: [
      TimelineEntry(
        actor: TimelineActor.human,
        author: 'Ada · on-call',
        status: 'Investigating',
        message:
            "Confirmed it's server-side on our end. Rolling back the latest release now.",
        time: '14:34',
        isPublic: true,
      ),
      TimelineEntry(
        actor: TimelineActor.ai,
        author: 'Uptizm AI',
        status: 'Analysis posted',
        message:
            'All regions return 503 with low latency: this is an origin-side fault, not a network or regional issue. Evidence and next steps posted.',
        time: '14:33',
      ),
      TimelineEntry(
        actor: TimelineActor.ai,
        author: 'Uptizm AI',
        status: 'Detected',
        message:
            '503 rate on pay.uptizm.com crossed the anomaly band (0.1% → 7.4%) across every region.',
        time: '14:32',
        isPublic: true,
      ),
    ],
    ai: IncidentAi(
      trigger: 'AI anomaly',
      confidence: AiConfidence.high,
      tldr:
          'Every region is getting HTTP 503 from pay.uptizm.com while response times stay low: the origin is up but actively rejecting requests, which points to a server-side fault rather than a network or regional problem. Uptizm watches from the outside, so the exact cause inside your service is yours to confirm.',
      evidenceFor: [
        AiEvidence(
          label: 'All regions affected',
          detail: 'us-east and eu-west both return 503; no region is spared.',
          source: 'regions us-east, eu-west',
        ),
        AiEvidence(
          label: 'Fast failures',
          detail:
              '503s come back in under 120ms, so the server responds but refuses the request.',
          source: 'metric response_time',
        ),
        AiEvidence(
          label: 'Sustained, not flapping',
          detail: '14 minutes of consecutive 503s on every check.',
          source: 'consecutive checks',
        ),
      ],
      evidenceAgainst: [
        AiEvidence(
          label: 'Not regional',
          detail: 'No single region is isolated; the pattern is global.',
        ),
        AiEvidence(
          label: 'Not a timeout',
          detail: 'Status is 503, not 504 or connection-refused.',
          source: 'status_code 503',
        ),
      ],
      suggestedActions: [
        AiSuggestedAction(
          title: 'Check your origin and the services it calls',
          rationale:
              'Uptizm sees a clean server-side 503, so the fault is in your service or a dependency it depends on, not the network path.',
        ),
        AiSuggestedAction(
          title: 'Leave the incident open',
          rationale:
              'Uptizm auto-detects recovery the moment checks pass again.',
        ),
      ],
      similarIncidents: [
        AiSimilarIncident(
          title: 'Checkout 503s, origin returning errors',
          similarity: 0.88,
        ),
        AiSimilarIncident(
          title: 'Payment endpoint 5xx spike',
          similarity: 0.71,
        ),
      ],
    ),
  ),

  // 2. Active AI-owned degradation.
  IncidentSummary(
    id: 'api-latency',
    title: 'Elevated p95 latency on API gateway',
    impact: IncidentImpact.degraded,
    severity: IncidentSeverity.warning,
    signalSource: SignalSource.anomaly,
    lifecycle: IncidentLifecycle.identified,
    startedAt: 'started 1h ago',
    duration: '1h 06m',
    affectedCount: 1,
    aiOwned: true,
    monitorName: 'API gateway',
    affectedMonitors: [
      AffectedMonitor(
        name: 'API gateway',
        statusAtStart: StatusKey.degraded,
        statusCurrent: StatusKey.degraded,
      ),
    ],
    timeline: [
      TimelineEntry(
        actor: TimelineActor.human,
        author: 'Ravi · on-call',
        status: 'Identified',
        message:
            'Confirmed: traffic is up and our origin is CPU-bound. Adding capacity now.',
        time: '13:40',
        isPublic: true,
      ),
      TimelineEntry(
        actor: TimelineActor.ai,
        author: 'Uptizm AI',
        status: 'Detected',
        message:
            'p95 climbing with no errors; your cpu_load metric crossed critical ~5m earlier. Looks load-driven.',
        time: '13:31',
        isPublic: true,
      ),
    ],
    ai: IncidentAi(
      trigger: 'AI anomaly',
      confidence: AiConfidence.medium,
      tldr:
          'p95 latency on API gateway has climbed for an hour with no errors: every check still returns 200. Your own cpu_load metric crossed its critical bound about 5 minutes before latency rose, and request_rate is at a 24h high, so this reads as load-driven saturation on your origin rather than a network or Uptizm-side issue. It\'s a correlation, not proof.',
      evidenceFor: [
        AiEvidence(
          label: 'cpu_load crossed critical first',
          detail:
              'Your custom metric breached the critical bound ~5 min before p95 started rising.',
          source: 'metric cpu_load',
        ),
        AiEvidence(
          label: 'Traffic at a 24h peak',
          detail: 'request_rate is higher than any point in the last day.',
          source: 'metric request_rate',
        ),
        AiEvidence(
          label: 'Slower, not failing',
          detail: 'p95 roughly tripled while status codes stayed 200.',
          source: 'metric response_time',
        ),
      ],
      evidenceAgainst: [
        AiEvidence(
          label: 'No errors',
          detail: "No 5xx or timeouts on any region's checks.",
          source: 'status_code 200',
        ),
        AiEvidence(
          label: 'Correlation only',
          detail:
              "Uptizm sees the metrics line up but can't confirm causation inside your app.",
        ),
      ],
      suggestedActions: [
        AiSuggestedAction(
          title: 'Check origin capacity and autoscaling',
          rationale:
              'cpu_load leads the latency rise, the classic shape of resource saturation.',
        ),
        AiSuggestedAction(
          title: 'Confirm whether this traffic is expected',
          rationale:
              'request_rate is peaking; if the load is organic you may need more headroom, not a fix.',
        ),
      ],
      similarIncidents: [
        AiSimilarIncident(
          title: 'API gateway latency under peak load',
          similarity: 0.79,
        ),
      ],
    ),
  ),

  // 3. Resolved threshold incident (no AI analysis, no assignee).
  IncidentSummary(
    id: 'eu-packet-loss',
    title: 'EU region packet loss',
    impact: IncidentImpact.down,
    severity: IncidentSeverity.critical,
    signalSource: SignalSource.threshold,
    lifecycle: IncidentLifecycle.resolved,
    startedAt: 'resolved 2h ago',
    duration: '1h 08m',
    affectedCount: 2,
    aiOwned: false,
    monitorName: 'Marketing site',
    affectedMonitors: [
      AffectedMonitor(
        name: 'Marketing site',
        statusAtStart: StatusKey.down,
        statusCurrent: StatusKey.up,
      ),
      AffectedMonitor(
        name: 'Docs',
        statusAtStart: StatusKey.degraded,
        statusCurrent: StatusKey.up,
      ),
    ],
    timeline: [
      TimelineEntry(
        actor: TimelineActor.human,
        author: 'Mara · on-call',
        status: 'Resolved',
        message:
            'Upstream provider confirmed resolution. Metrics back to baseline across all EU probes.',
        time: '11:50',
        isPublic: true,
      ),
      TimelineEntry(
        actor: TimelineActor.human,
        author: 'Mara · on-call',
        status: 'Monitoring',
        message: 'Applied failover to eu-central. Watching recovery.',
        time: '11:05',
        isPublic: true,
      ),
      TimelineEntry(
        actor: TimelineActor.system,
        status: 'Threshold breach',
        message:
            'Packet loss across eu-west probes crossed the 5% critical bound for 3 consecutive checks.',
        time: '10:42',
      ),
    ],
  ),

  // 4. Active manual maintenance window.
  IncidentSummary(
    id: 'maintenance-db',
    title: 'Scheduled database maintenance',
    impact: IncidentImpact.info,
    severity: IncidentSeverity.info,
    signalSource: SignalSource.manual,
    lifecycle: IncidentLifecycle.monitoring,
    startedAt: 'started 32m ago',
    duration: '32m',
    affectedCount: 2,
    aiOwned: false,
    monitorName: 'API gateway',
    affectedMonitors: [
      AffectedMonitor(
        name: 'API gateway',
        statusAtStart: StatusKey.info,
        statusCurrent: StatusKey.info,
      ),
      AffectedMonitor(
        name: 'Checkout service',
        statusAtStart: StatusKey.info,
        statusCurrent: StatusKey.info,
      ),
    ],
    timeline: [
      TimelineEntry(
        actor: TimelineActor.human,
        author: 'Platform team',
        status: 'Monitoring',
        message:
            'Maintenance window in progress. Brief read-only period expected for the next 30 minutes.',
        time: '14:00',
        isPublic: true,
      ),
    ],
  ),

  // 5. Auto-resolved AI blip.
  IncidentSummary(
    id: 'docs-blip',
    title: 'Brief latency blip on Docs, auto-resolved',
    impact: IncidentImpact.degraded,
    severity: IncidentSeverity.info,
    signalSource: SignalSource.anomaly,
    lifecycle: IncidentLifecycle.resolved,
    startedAt: 'resolved 5h ago',
    duration: '6m',
    affectedCount: 1,
    aiOwned: true,
    monitorName: 'Docs',
    affectedMonitors: [
      AffectedMonitor(
        name: 'Docs',
        statusAtStart: StatusKey.degraded,
        statusCurrent: StatusKey.up,
      ),
    ],
    timeline: [
      TimelineEntry(
        actor: TimelineActor.ai,
        author: 'Uptizm AI',
        status: 'Auto-resolved',
        message:
            'p95 returned to its expected band and held for 5 consecutive checks. Closed automatically (Auto mode).',
        time: '09:18',
        isPublic: true,
        autonomous: true,
      ),
      TimelineEntry(
        actor: TimelineActor.ai,
        author: 'Uptizm AI',
        status: 'Detected',
        message:
            'p95 briefly rose above the expected band, isolated to eu-central. No errors.',
        time: '09:12',
        isPublic: true,
      ),
    ],
    ai: IncidentAi(
      trigger: 'AI anomaly',
      confidence: AiConfidence.medium,
      tldr:
          'A short latency blip on Docs from eu-central that cleared on its own within 6 minutes. No errors and no other region was affected, so Uptizm closed it automatically under Auto mode.',
      evidenceFor: [
        AiEvidence(
          label: 'Self-recovered',
          detail: 'p95 returned to the expected band and held for 5 checks.',
          source: 'metric response_time',
        ),
        AiEvidence(
          label: 'Single region',
          detail: 'Only eu-central probes were briefly elevated.',
          source: 'region eu-central',
        ),
      ],
      evidenceAgainst: [
        AiEvidence(
          label: 'No errors',
          detail: 'All checks returned 200 throughout.',
          source: 'status_code 200',
        ),
      ],
      suggestedActions: [
        AiSuggestedAction(
          title: 'No action needed',
          rationale:
              'Uptizm closed this automatically; review only if the pattern recurs.',
        ),
      ],
      similarIncidents: [],
    ),
  ),
];

// ---------------------------------------------------------------------------
// Lookup helpers
// ---------------------------------------------------------------------------

/// Find an incident fixture by [id]. Returns `null` when none matches.
IncidentSummary? findIncident(String? id) {
  if (id == null) return null;
  for (final i in incidents) {
    if (i.id == id) return i;
  }
  return null;
}

/// All incidents that touch the given [monitorName] (primary or affected list).
List<IncidentSummary> incidentsForMonitor(String monitorName) {
  return incidents.where((i) {
    if (i.monitorName == monitorName) return true;
    return i.affectedMonitors.any((m) => m.name == monitorName);
  }).toList();
}

// ---------------------------------------------------------------------------
// Shared derivations
// ---------------------------------------------------------------------------

/// Active incidents: everything not yet resolved, newest-first as fixtured.
///
/// Single source for both `DashboardController` and `IncidentController` so the
/// not-resolved filter is never duplicated across the two controllers.
List<IncidentSummary> get activeIncidents =>
    incidents.where((i) => i.lifecycle != IncidentLifecycle.resolved).toList();

/// AI inbox entries: active incidents that carry an AI analysis payload.
///
/// Only incidents with a non-null `ai` payload qualify; the rest stay in the
/// plain incident list.
List<IncidentSummary> get aiSuggestions =>
    activeIncidents.where((i) => i.ai != null).toList();
