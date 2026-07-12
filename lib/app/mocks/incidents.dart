import 'package:flutter/foundation.dart';

import 'status.dart';

// ---------------------------------------------------------------------------
// Wire decoders (safe enum fallback)
// ---------------------------------------------------------------------------

/// Decodes the backend `lifecycle` wire value into an [IncidentLifecycle],
/// falling back to [IncidentLifecycle.detected] on an unknown value so a
/// stale client never crashes on a lifecycle stage it does not yet know.
IncidentLifecycle lifecycleFromWire(String? raw) {
  if (raw == null) return IncidentLifecycle.detected;
  return IncidentLifecycle.values.firstWhere(
    (v) => v.name == raw,
    orElse: () => IncidentLifecycle.detected,
  );
}

/// Decodes the backend `severity` wire value into an [IncidentSeverity].
///
/// The backend uses `'warn'` where the mock enum spells out `warning`;
/// everything else falls back to [IncidentSeverity.info].
IncidentSeverity severityFromWire(String? raw) {
  return switch (raw) {
    'critical' => IncidentSeverity.critical,
    'warn' => IncidentSeverity.warning,
    'info' => IncidentSeverity.info,
    _ => IncidentSeverity.info,
  };
}

/// Decodes the backend `signal_source` wire value into a [SignalSource],
/// falling back to [SignalSource.manual] on an unknown value.
SignalSource signalSourceFromWire(String? raw) {
  return switch (raw) {
    'user_threshold' => SignalSource.threshold,
    'ai_anomaly' => SignalSource.anomaly,
    'manual' => SignalSource.manual,
    _ => SignalSource.manual,
  };
}

/// Derives the customer-facing [IncidentImpact] badge from the backend
/// `impact` wire value (`none`/`minor`/`major`/`critical`), the vocabulary
/// the mock's three-value [IncidentImpact] does not share 1:1: `critical`
/// and `major` both read as a full [IncidentImpact.down], `minor` reads as
/// [IncidentImpact.degraded], and `none` (or anything unrecognized) reads
/// as [IncidentImpact.info].
IncidentImpact impactFromWire(String? raw) {
  return switch (raw) {
    'critical' || 'major' => IncidentImpact.down,
    'minor' => IncidentImpact.degraded,
    'none' => IncidentImpact.info,
    _ => IncidentImpact.info,
  };
}

/// Decodes the backend `ai.confidence` wire value into an [AiConfidence],
/// falling back to [AiConfidence.low] on an unknown value so an unrecognized
/// confidence string never crashes the inbox and instead reads as the most
/// conservative tier.
AiConfidence aiConfidenceFromWire(String? raw) {
  if (raw == null) return AiConfidence.low;
  return AiConfidence.values.firstWhere(
    (v) => v.name == raw,
    orElse: () => AiConfidence.low,
  );
}

/// Decodes the backend `actor` wire value into a [TimelineActor], falling
/// back to [TimelineActor.system] on an unknown value.
TimelineActor timelineActorFromWire(String? raw) {
  if (raw == null) return TimelineActor.system;
  return TimelineActor.values.firstWhere(
    (v) => v.name == raw,
    orElse: () => TimelineActor.system,
  );
}

/// Formats an ISO-8601 timestamp string as a local `HH:mm` wall-clock
/// string. Returns `'—'` when [raw] is `null` or fails to parse.
String formatHourMinute(String? raw) {
  if (raw == null) return '—';
  final DateTime? parsed = DateTime.tryParse(raw);
  if (parsed == null) return '—';
  final DateTime local = parsed.toLocal();
  String two(int n) => n.toString().padLeft(2, '0');
  return '${two(local.hour)}:${two(local.minute)}';
}

/// Formats the elapsed time between [startedAt] and [until] (`resolvedAt` or
/// now) as `"Xm"` when under an hour, or `"Xh YYm"` otherwise. Matches the
/// fixture duration convention (e.g. `'14m'`, `'1h 06m'`).
String formatDuration(DateTime startedAt, DateTime until) {
  final Duration elapsed = until.difference(startedAt);
  final int totalMinutes = elapsed.inMinutes.abs();
  if (totalMinutes < 60) return '${totalMinutes}m';
  final int hours = totalMinutes ~/ 60;
  final int minutes = totalMinutes % 60;
  return '${hours}h ${minutes.toString().padLeft(2, '0')}m';
}

/// Formats the relative-time meta line (e.g. `"started 14m ago"` or
/// `"resolved 2h ago"`) from [startedAt]/[resolvedAt].
String formatRelativeMeta(DateTime startedAt, DateTime? resolvedAt) {
  final bool isResolved = resolvedAt != null;
  final DateTime reference = resolvedAt ?? startedAt;
  final Duration elapsed = DateTime.now().difference(reference);
  final int minutes = elapsed.inMinutes.abs();
  final String magnitude = minutes < 60 ? '${minutes}m' : '${elapsed.inHours}h';
  return '${isResolved ? 'resolved' : 'started'} $magnitude ago';
}

// ---------------------------------------------------------------------------
// Domain types
// ---------------------------------------------------------------------------

/// Customer-facing impact level; a subset of [StatusKey].
///
/// Only statuses that meaningfully describe visible impact are included:
/// service is completely down, partially degraded, or in a planned maintenance
/// window.
enum IncidentImpact {
  /// Service is completely unavailable.
  down,

  /// Service is slower or partially impaired.
  degraded,

  /// Planned maintenance; no unexpected impact.
  info;

  /// Maps to the equivalent [StatusKey] for badge rendering.
  StatusKey get statusKey => switch (this) {
    IncidentImpact.down => StatusKey.down,
    IncidentImpact.degraded => StatusKey.degraded,
    IncidentImpact.info => StatusKey.info,
  };
}

/// Operator-side severity tier, independent of customer-facing [IncidentImpact].
enum IncidentSeverity {
  /// Requires immediate response; business impact is severe.
  critical,

  /// Elevated concern; monitoring closely but not emergency.
  warning,

  /// Low-risk informational event.
  info;

  /// Human-readable display label.
  String get label => switch (this) {
    IncidentSeverity.critical => 'Critical',
    IncidentSeverity.warning => 'Warning',
    IncidentSeverity.info => 'Info',
  };
}

/// How the incident was first detected.
enum SignalSource {
  /// A configured numeric threshold was breached.
  threshold,

  /// Uptizm AI detected an anomaly in a learned baseline.
  anomaly,

  /// Created manually by an operator.
  manual;

  /// Human-readable display label for timeline and filter chips.
  String get label => switch (this) {
    SignalSource.threshold => 'Threshold breach',
    SignalSource.anomaly => 'AI anomaly',
    SignalSource.manual => 'Manual',
  };
}

/// Lifecycle stage an incident moves through.
enum IncidentLifecycle {
  detected,
  investigating,
  identified,
  monitoring,
  resolved;

  /// Display label matching the design source (title-case).
  String get label => switch (this) {
    IncidentLifecycle.detected => 'Detected',
    IncidentLifecycle.investigating => 'Investigating',
    IncidentLifecycle.identified => 'Identified',
    IncidentLifecycle.monitoring => 'Monitoring',
    IncidentLifecycle.resolved => 'Resolved',
  };
}

/// Actor that authored a timeline entry.
enum TimelineActor {
  /// A human operator or on-call engineer.
  human,

  /// Uptizm AI.
  ai,

  /// The Uptizm platform itself (threshold triggers, auto-resolution).
  system,
}

// ---------------------------------------------------------------------------
// Value objects
// ---------------------------------------------------------------------------

/// A monitor affected by an incident, with its status at open time and now.
@immutable
class AffectedMonitor {
  /// Display name of the monitor.
  final String name;

  /// Status when the incident was first opened.
  final StatusKey statusAtStart;

  /// Current status (may have recovered).
  final StatusKey statusCurrent;

  const AffectedMonitor({
    required this.name,
    required this.statusAtStart,
    required this.statusCurrent,
  });

  /// Builds an [AffectedMonitor] from a backend `monitors[]` pivot entry
  /// (`component_status_at_start`/`component_status_current` snake_case
  /// keys), with a safe fallback for unknown status values.
  factory AffectedMonitor.fromMap(Map<String, dynamic> map) {
    return AffectedMonitor(
      name: (map['name'] as String?) ?? '',
      statusAtStart: statusKeyFromWire(
        map['component_status_at_start'] as String?,
      ),
      statusCurrent: statusKeyFromWire(
        map['component_status_current'] as String?,
      ),
    );
  }
}

/// One entry in an incident's activity timeline.
@immutable
class TimelineEntry {
  /// Who acted.
  final TimelineActor actor;

  /// Display name of the author (operator handle, `"Uptizm AI"`, or `null`
  /// for system entries that have no named author).
  final String? author;

  /// Short status label shown next to the timestamp, e.g. `"Investigating"`.
  final String status;

  /// Narrative body of the update.
  final String message;

  /// Wall-clock time string, e.g. `"14:34"`.
  final String time;

  /// Whether this entry is visible on the public status page.
  final bool isPublic;

  /// Whether this update was posted autonomously by AI without human input.
  final bool autonomous;

  const TimelineEntry({
    required this.actor,
    this.author,
    required this.status,
    required this.message,
    required this.time,
    this.isPublic = false,
    this.autonomous = false,
  });

  /// Builds a [TimelineEntry] from a backend `updates[]` payload
  /// (`message`/`is_public`/`autonomous`/`actor`/`status` snake_case keys).
  ///
  /// The resource does not carry a named author field, so [author] stays
  /// `null` for a backend-decoded entry (the UI already renders a `null`
  /// author for system entries).
  factory TimelineEntry.fromMap(Map<String, dynamic> map) {
    return TimelineEntry(
      actor: timelineActorFromWire(map['actor'] as String?),
      status: (map['status'] as String?) ?? '',
      message: (map['message'] as String?) ?? '',
      time: formatHourMinute(
        (map['display_at'] ?? map['created_at']) as String?,
      ),
      isPublic: map['is_public'] == true,
      autonomous: map['autonomous'] == true,
    );
  }
}

/// A single piece of evidence in the AI analysis (for or against a hypothesis).
@immutable
class AiEvidence {
  /// Short heading, e.g. `"All regions affected"`.
  final String label;

  /// Expanded explanation shown in the detail card.
  final String detail;

  /// Optional data source citation, e.g. `"regions us-east, eu-west"`.
  final String? source;

  const AiEvidence({required this.label, required this.detail, this.source});
}

/// A suggested remediation step from Uptizm AI.
@immutable
class AiSuggestedAction {
  /// Imperative action title, e.g. `"Check your origin"`.
  final String title;

  /// Reasoning that supports the suggestion.
  final String rationale;

  const AiSuggestedAction({required this.title, required this.rationale});
}

/// A historically similar incident surfaced by the AI.
@immutable
class AiSimilarIncident {
  /// Title of the past incident.
  final String title;

  /// Cosine similarity score in the range [0, 1].
  final double similarity;

  const AiSimilarIncident({required this.title, required this.similarity});
}

/// Full AI analysis attached to an incident.
@immutable
class IncidentAi {
  /// What triggered the analysis, e.g. `"AI anomaly"`.
  final String trigger;

  /// AI's self-reported confidence level.
  final AiConfidence confidence;

  /// One-paragraph plain-English summary.
  final String tldr;

  /// Evidence supporting the AI's hypothesis.
  final List<AiEvidence> evidenceFor;

  /// Evidence that qualifies or contradicts the hypothesis.
  final List<AiEvidence> evidenceAgainst;

  /// Ordered list of suggested remediation steps.
  final List<AiSuggestedAction> suggestedActions;

  /// Past incidents the AI considers similar.
  final List<AiSimilarIncident> similarIncidents;

  const IncidentAi({
    required this.trigger,
    required this.confidence,
    required this.tldr,
    required this.evidenceFor,
    required this.evidenceAgainst,
    required this.suggestedActions,
    required this.similarIncidents,
  });
}

/// AI confidence level for an incident analysis.
enum AiConfidence { high, medium, low }

/// Assignee for an incident: the engineer currently driving it.
@immutable
class IncidentAssignee {
  /// Full display name.
  final String name;

  /// Two-letter initials for avatar fallback rendering.
  final String initials;

  const IncidentAssignee({required this.name, required this.initials});
}

/// Acknowledgement record: a human confirmed they are on the incident.
@immutable
class IncidentAcknowledgement {
  /// Name of the engineer who acknowledged.
  final String by;

  /// Wall-clock time of acknowledgement, e.g. `"14:33"`.
  final String at;

  const IncidentAcknowledgement({required this.by, required this.at});
}

/// Full incident record as shown in the incident list and detail page.
@immutable
class IncidentSummary {
  /// Stable identifier used for routing, e.g. `'checkout-503'`.
  final String id;

  /// Incident headline.
  final String title;

  /// Customer-facing impact classification.
  final IncidentImpact impact;

  /// Operator-side severity tier.
  final IncidentSeverity severity;

  /// How the incident was detected.
  final SignalSource signalSource;

  /// Current lifecycle stage.
  final IncidentLifecycle lifecycle;

  /// Relative time string for the list row, e.g. `"started 14m ago"`.
  final String startedAt;

  /// Human-readable elapsed duration, e.g. `"14m"` or `"1h 08m"`.
  final String duration;

  /// Number of monitors affected.
  final int affectedCount;

  /// Whether Uptizm AI owns this incident's response.
  final bool aiOwned;

  /// Primary monitor name for the detail header meta line.
  final String monitorName;

  /// All monitors affected, with their status transitions.
  final List<AffectedMonitor> affectedMonitors;

  /// Chronological (newest-first) activity log.
  final List<TimelineEntry> timeline;

  /// Assignee, or `null` when the incident is unassigned.
  final IncidentAssignee? assignee;

  /// Acknowledgement record, or `null` when not yet acknowledged.
  final IncidentAcknowledgement? acknowledged;

  /// Attached AI analysis, or `null` when AI analysis is absent.
  final IncidentAi? ai;

  const IncidentSummary({
    required this.id,
    required this.title,
    required this.impact,
    required this.severity,
    required this.signalSource,
    required this.lifecycle,
    required this.startedAt,
    required this.duration,
    required this.affectedCount,
    required this.aiOwned,
    required this.monitorName,
    required this.affectedMonitors,
    required this.timeline,
    this.assignee,
    this.acknowledged,
    this.ai,
  });

  /// Builds an [IncidentSummary] from an `IncidentResource` payload (backend
  /// `api/v1` snake_case keys).
  ///
  /// [monitorName] is resolved from the `monitors[]` pivot list by matching
  /// each entry's `monitor_id` against `primary_monitor_id` (the key the
  /// backend `IncidentResource` emits), falling back to the first affected
  /// monitor, and reads that entry's `name` for the header meta line.
  /// [assignee] and [acknowledged] have no counterpart in the resource shape
  /// above and stay `null` on a backend-decoded instance; they are wired in
  /// separately (an assignment endpoint). [ai] decodes when the map carries
  /// an `ai` sub-object (the `GET /dashboard/ai-inbox` shape); the evidence,
  /// suggested-action, and similar-incident lists have no counterpart there
  /// yet and stay empty.
  factory IncidentSummary.fromMap(Map<String, dynamic> map) {
    final Object? rawMonitors = map['monitors'];
    final List<Map<String, dynamic>> monitorMaps = rawMonitors is List
        ? rawMonitors.whereType<Map<String, dynamic>>().toList()
        : const [];
    final List<AffectedMonitor> affectedMonitors = monitorMaps
        .map(AffectedMonitor.fromMap)
        .toList();

    final Object? rawUpdates = map['updates'];
    final List<TimelineEntry> timeline = rawUpdates is List
        ? rawUpdates
              .whereType<Map<String, dynamic>>()
              .map(TimelineEntry.fromMap)
              .toList()
        : const [];

    final String? primaryMonitorId = map['primary_monitor_id']?.toString();
    final Map<String, dynamic>? primaryMonitorMap = monitorMaps.isEmpty
        ? null
        : monitorMaps.firstWhere(
            (m) => m['monitor_id']?.toString() == primaryMonitorId,
            orElse: () => monitorMaps.first,
          );

    final DateTime startedAt =
        DateTime.tryParse(map['started_at'] as String? ?? '') ??
        DateTime.now();
    final DateTime? resolvedAt = map['resolved_at'] is String
        ? DateTime.tryParse(map['resolved_at'] as String)
        : null;

    final Object? rawAi = map['ai'];
    final IncidentAi? ai = rawAi is Map
        ? IncidentAi(
            trigger: (rawAi['trigger'] as String?) ?? '',
            confidence: aiConfidenceFromWire(rawAi['confidence'] as String?),
            tldr: (rawAi['tldr'] as String?) ?? '',
            evidenceFor: const [],
            evidenceAgainst: const [],
            suggestedActions: const [],
            similarIncidents: const [],
          )
        : null;

    return IncidentSummary(
      id: map['id']?.toString() ?? '',
      title: (map['title'] as String?) ?? '',
      impact: impactFromWire(map['impact'] as String?),
      severity: severityFromWire(map['severity'] as String?),
      signalSource: signalSourceFromWire(map['signal_source'] as String?),
      lifecycle: lifecycleFromWire(map['lifecycle'] as String?),
      startedAt: formatRelativeMeta(startedAt, resolvedAt),
      duration: formatDuration(startedAt, resolvedAt ?? DateTime.now()),
      affectedCount: monitorMaps.length,
      aiOwned: map['ai_owned'] == true,
      monitorName: (primaryMonitorMap?['name'] as String?) ?? '',
      affectedMonitors: affectedMonitors,
      timeline: timeline,
      ai: ai,
    );
  }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/// Design-lab incident fixtures. Five incidents covering all lifecycle stages,
/// signal sources, severities, and AI/manual ownership states.
const List<IncidentSummary> incidents = [
  // 1. Active AI-owned outage.
  IncidentSummary(
    id: 'checkout-503',
    title: 'Checkout service returning 503s across all regions',
    impact: IncidentImpact.down,
    severity: IncidentSeverity.critical,
    signalSource: SignalSource.anomaly,
    lifecycle: IncidentLifecycle.investigating,
    startedAt: 'started 14m ago',
    duration: '14m',
    affectedCount: 1,
    aiOwned: true,
    monitorName: 'Checkout service',
    assignee: IncidentAssignee(name: 'Ada Lovelace', initials: 'AL'),
    acknowledged: IncidentAcknowledgement(by: 'Ada Lovelace', at: '14:33'),
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
    assignee: IncidentAssignee(name: 'Ravi Shah', initials: 'RS'),
    acknowledged: IncidentAcknowledgement(by: 'Ravi Shah', at: '13:35'),
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
