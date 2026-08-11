import 'package:flutter/foundation.dart';

import '../enums/ai_confidence.dart' show AiConfidence;
import '../enums/ai_degrade_reason.dart' show AiDegradeReason;
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
  /// The affected monitor's id, or `''` when the pivot entry omits it.
  ///
  /// Carried so a caller can match an incident to a monitor by IDENTITY rather
  /// than by display name: two monitors may share a name, and a rename would
  /// silently break a name match.
  final String id;

  /// Display name of the monitor.
  final String name;

  /// Status when the incident was first opened.
  final StatusKey statusAtStart;

  /// Current status (may have recovered).
  final StatusKey statusCurrent;

  const AffectedMonitor({
    this.id = '',
    required this.name,
    required this.statusAtStart,
    required this.statusCurrent,
  });

  /// Builds an [AffectedMonitor] from a backend `monitors[]` pivot entry
  /// (`component_status_at_start`/`component_status_current` snake_case
  /// keys), with a safe fallback for unknown status values.
  factory AffectedMonitor.fromMap(Map<String, dynamic> map) {
    return AffectedMonitor(
      id: map['monitor_id']?.toString() ?? '',
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
  /// (`message`/`is_public`/`autonomous`/`actor`/`author`/`status` snake_case
  /// keys).
  ///
  /// [author] carries the real persisted attribution the backend wrote from
  /// the acting user (`IncidentUpdateResource.author`); a blank or absent value
  /// decodes to `null`, which the UI already renders as an unattributed system
  /// entry. The client never substitutes a name of its own here.
  factory TimelineEntry.fromMap(Map<String, dynamic> map) {
    final String author = (map['author'] as String?)?.trim() ?? '';

    return TimelineEntry(
      actor: timelineActorFromWire(map['actor'] as String?),
      author: author.isEmpty ? null : author,
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

  /// Builds an [AiEvidence] from a backend `evidence_for`/`evidence_against`
  /// row (`{label, detail, source}` snake_case keys; `source` is one of the
  /// backend's `timeline|check|monitor` enum values, decoded as a plain
  /// string since the client only ever displays it as a citation tag).
  factory AiEvidence.fromMap(Map<String, dynamic> map) {
    return AiEvidence(
      label: (map['label'] as String?) ?? '',
      detail: (map['detail'] as String?) ?? '',
      source: map['source'] as String?,
    );
  }
}

/// A suggested remediation step from Uptizm AI.
@immutable
class AiSuggestedAction {
  /// Imperative action title, e.g. `"Check your origin"`.
  final String title;

  /// Reasoning that supports the suggestion.
  final String rationale;

  const AiSuggestedAction({required this.title, required this.rationale});

  /// Builds an [AiSuggestedAction] from a backend `suggested_actions` row
  /// (`{title, rationale}` snake_case keys).
  factory AiSuggestedAction.fromMap(Map<String, dynamic> map) {
    return AiSuggestedAction(
      title: (map['title'] as String?) ?? '',
      rationale: (map['rationale'] as String?) ?? '',
    );
  }
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

  /// Why the backend answered from a deterministic baseline instead of the
  /// model, or `null` when the analysis is the model's own.
  ///
  /// The one field here that is NOT `required`, against the convention of its
  /// seven siblings, and for two reasons. `null` is a real state ("nothing
  /// degraded"), not a missing value, so optional is the honest type. And the
  /// three fixture construction sites in `lib/app/mocks/incidents.dart` plus the
  /// one in `ai_analysis_card_test.dart` name all seven arguments and know
  /// nothing about degradation; a required eighth would break them at compile
  /// time for no gain.
  final AiDegradeReason? degradeReason;

  const IncidentAi({
    required this.trigger,
    required this.confidence,
    required this.tldr,
    required this.evidenceFor,
    required this.evidenceAgainst,
    required this.suggestedActions,
    required this.similarIncidents,
    this.degradeReason,
  });
}

/// Acknowledgement record: a human confirmed they are on the incident.
///
/// Only ever built from a PERSISTED timeline entry (see
/// `Incident.acknowledgement`): both fields come from what the backend stamped,
/// so this type can never carry a client-invented responder.
@immutable
class IncidentAcknowledgement {
  /// Name of the engineer who acknowledged, as the backend recorded it.
  final String by;

  /// Wall-clock time of acknowledgement, e.g. `"14:33"`.
  final String at;

  const IncidentAcknowledgement({required this.by, required this.at});
}

/// Full incident record as shown in the incident list and detail page.
///
/// Fixture-only design-lab DTO. The wire-decode role moved to the `Incident`
/// ORM model; this shape survives solely to carry the design-lab `incidents`
/// fixtures, which hold richer data than any backend endpoint emits (the full
/// [IncidentAi] evidence lists) for the design-lab-only surfaces: the weekly AI
/// digest, the public status-page preview, the monitor-detail incidents tab, and
/// the AI analysis card preview.
///
/// It deliberately carries NO assignee and NO acknowledgement: both are real
/// persisted state now, read off the live [IncidentAcknowledgement] /
/// `Incident.assigneeId` path, and a fixture field for either would only be a
/// place for an invented responder to live.
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

  /// Human-readable elapsed duration, localized: `"14m"` / `"1h 08m"` in
  /// English, `"14dk"` / `"1sa 08dk"` in Turkish. Formatted by
  /// `formatDuration()`, which reads its units from the catalogue.
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
    this.ai,
  });
}
