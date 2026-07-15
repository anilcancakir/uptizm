import 'package:flutter/foundation.dart';

import '../enums/ai_confidence.dart' show AiConfidence;
import '../enums/incident_impact.dart' show IncidentImpact;
import '../enums/incident_lifecycle.dart' show IncidentLifecycle;
import '../enums/incident_severity.dart' show IncidentSeverity;
import '../enums/signal_source.dart' show SignalSource;
import '../enums/status_key.dart' show StatusKey, statusKeyFromWire;
import '../enums/timeline_actor.dart' show TimelineActor, timelineActorFromWire;
import 'formatters.dart' show formatHourMinute;

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
///
/// Fixture-only design-lab DTO. The wire-decode role moved to the `Incident`
/// ORM model; this shape survives solely to carry the design-lab `incidents`
/// fixtures, which hold richer data than any backend endpoint emits (per-item
/// [assignee]/[acknowledged], and the full [IncidentAi] evidence lists) for the
/// design-lab-only surfaces: the weekly AI digest, the public status-page
/// preview, the monitor-detail incidents tab, and the AI analysis card preview.
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
}
