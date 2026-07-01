import 'package:uptizm/app/mocks/billing.dart';
import 'package:uptizm/app/mocks/incidents.dart' as mocks;
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/resources/views/monitor_metrics_support.dart';
import 'package:uptizm/ui/components/incident_timeline/incident_timeline.dart';
import 'package:uptizm/ui/components/region_picker/region_picker.dart';

// ---------------------------------------------------------------------------
// Option-list constants (label / value pairs for the incident create form's
// kind/severity/impact controls, and the detail composer's status Select).
//
// Values match the KINDS / SEVERITIES / IMPACTS constants in the React
// IncidentCreatePage.tsx source and STATUS_OPTIONS in IncidentDetailPage.tsx.
// ---------------------------------------------------------------------------

/// Incident kinds: a real incident, or a scheduled maintenance window.
/// Matches `KINDS` in IncidentCreatePage.tsx.
const List<MetricOption> kIncidentKinds = [
  MetricOption(label: 'Incident', value: 'incident'),
  MetricOption(label: 'Scheduled maintenance', value: 'maintenance'),
];

/// Operator-side severity tiers offered on the create form. Matches
/// `SEVERITIES` in IncidentCreatePage.tsx.
const List<MetricOption> kIncidentSeverities = [
  MetricOption(label: 'Critical', value: 'critical'),
  MetricOption(label: 'Warning', value: 'warning'),
  MetricOption(label: 'Info', value: 'info'),
];

/// Customer-facing status-page impact levels offered on the create form.
/// Matches `IMPACTS` in IncidentCreatePage.tsx.
const List<MetricOption> kIncidentImpacts = [
  MetricOption(label: 'Major outage', value: 'down'),
  MetricOption(label: 'Degraded performance', value: 'degraded'),
  MetricOption(label: 'Maintenance', value: 'info'),
];

/// Lifecycle stages offered in the detail composer's status Select. Matches
/// `STATUS_OPTIONS` in IncidentDetailPage.tsx.
const List<MetricOption> kIncidentStatuses = [
  MetricOption(label: 'Detected', value: 'Detected'),
  MetricOption(label: 'Investigating', value: 'Investigating'),
  MetricOption(label: 'Identified', value: 'Identified'),
  MetricOption(label: 'Monitoring', value: 'Monitoring'),
  MetricOption(label: 'Resolved', value: 'Resolved'),
];

/// An anomaly's AI confidence sets where the operator-side severity starts.
/// Matches `SEVERITY_FROM_CONFIDENCE` in IncidentCreatePage.tsx.
///
/// ```dart
/// severityFromConfidence[AiConfidence.high] // 'critical'
/// ```
const Map<mocks.AiConfidence, String> severityFromConfidence = {
  mocks.AiConfidence.high: 'critical',
  mocks.AiConfidence.medium: 'warning',
  mocks.AiConfidence.low: 'info',
};

// ---------------------------------------------------------------------------
// Pure helper functions.
// ---------------------------------------------------------------------------

/// A signal-grounded draft of the next public update, in the operator's
/// voice. Ports `draftUpdate` from IncidentDetailPage.tsx.
///
/// Branches on [mocks.IncidentSummary.lifecycle] first (resolved), then on
/// manual/info maintenance, then falls back to the impact-driven
/// investigating copy.
String draftUpdate(mocks.IncidentSummary i) {
  if (i.lifecycle == mocks.IncidentLifecycle.resolved) {
    return 'This incident is resolved. ${i.monitorName} is back to normal '
        'across all regions and checks are passing again. Thanks for your '
        'patience.';
  }
  if (i.signalSource == mocks.SignalSource.manual &&
      i.impact == mocks.IncidentImpact.info) {
    return 'Scheduled maintenance on ${i.monitorName} is underway. You may '
        "notice a brief interruption while we work. We'll post here as soon "
        "as it's complete.";
  }

  final String what = switch (i.impact) {
    mocks.IncidentImpact.down => 'a major outage',
    mocks.IncidentImpact.degraded => 'degraded performance',
    mocks.IncidentImpact.info => 'a service issue',
  };
  final String signal = i.impact == mocks.IncidentImpact.down
      ? 'errors across regions'
      : 'elevated response times';

  return "We're investigating $what affecting ${i.monitorName}. Uptizm's "
      "checks are showing $signal. We'll share another update within 30 "
      'minutes.';
}

/// A postmortem draft built only from what Uptizm observed. Ports
/// `postmortemDraft` from IncidentDetailPage.tsx.
String postmortemDraft(mocks.IncidentSummary i) {
  final String monitorWord = i.affectedCount == 1 ? 'monitor' : 'monitors';
  return '${i.title} lasted ${i.duration} and affected ${i.affectedCount} '
      '$monitorWord. Uptizm first detected it via '
      '${i.signalSource.label.toLowerCase()}, then saw checks recover before '
      'it was resolved. This draft covers only what Uptizm observed from the '
      'outside; add the internal root cause before publishing.';
}

/// Maps the [monitors] fixture to [Region] instances expected by
/// [RegionPicker], for the affected-monitors multi-select. Mirrors
/// `probeRegionsToRegions` in monitor_form_support.dart.
///
/// ```dart
/// final regions = monitorsToRegions();
/// RegionPicker(regions: regions, value: selected, onChanged: _onChanged);
/// ```
List<Region> monitorsToRegions() {
  return [
    for (final MonitorSummary m in monitors)
      Region(label: m.name, value: m.id),
  ];
}

/// Maps [src] (a list of the mocks-layer [mocks.TimelineEntry]) to the
/// `incident_timeline` component's own [TimelineEntry], which the widget
/// tree consumes directly.
///
/// The two types intentionally stay separate (mocks vs. UI component); this
/// mapper is the bridge. Field mapping is direct: actor is re-keyed through
/// its `name` (both enums share `ai`/`human`/`system` member names), and
/// status/message/time/isPublic/author/autonomous pass through unchanged.
///
/// ```dart
/// IncidentTimeline(entries: toComponentTimeline(incident.timeline));
/// ```
List<TimelineEntry> toComponentTimeline(List<mocks.TimelineEntry> src) {
  return [
    for (final mocks.TimelineEntry e in src)
      TimelineEntry(
        actor: TimelineActor.values.byName(e.actor.name),
        status: e.status,
        message: e.message,
        time: e.time,
        isPublic: e.isPublic,
        author: e.author,
        autonomous: e.autonomous,
      ),
  ];
}

/// The cheapest plan whose AI capability covers full incident analysis.
///
/// Feeds the detail view's `UpgradeNudge(requiredPlan: ...)` when the
/// current tier does not unlock [AiLevel.analysis].
Plan planForAiAnalysis() {
  return smallestPlanWhere((l) => l.ai.index >= AiLevel.analysis.index);
}

// ---------------------------------------------------------------------------
// Assignee roster.
// ---------------------------------------------------------------------------

/// A minimal responder roster for the detail assignee Select.
///
/// No assignee roster exists elsewhere in the codebase (`oncall.dart` /
/// `teams.dart` expose escalation policies, not a roster; `incidents.dart`
/// only carries inline per-incident assignees), so this module defines one.
const List<mocks.IncidentAssignee> responders = [
  mocks.IncidentAssignee(name: 'Ada Lovelace', initials: 'AL'),
  mocks.IncidentAssignee(name: 'Ravi Shah', initials: 'RS'),
  mocks.IncidentAssignee(name: 'Mara Chen', initials: 'MC'),
  mocks.IncidentAssignee(name: 'Platform team', initials: 'PT'),
];
