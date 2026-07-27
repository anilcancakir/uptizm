import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/ai_confidence.dart' as mocks;
import 'package:uptizm/app/enums/incident_impact.dart' as mocks;
import 'package:uptizm/app/enums/incident_lifecycle.dart' as mocks;
import 'package:uptizm/app/enums/signal_source.dart' as mocks;
import 'package:uptizm/app/support/incident_types.dart'
    as mocks
    show TimelineEntry;
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/models/incident.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_support.dart';
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
///
/// A getter (not a `const`) so each display label resolves through [trans] at
/// the current locale; the [MetricOption.value] wire tokens stay fixed. Matches
/// `KINDS` in IncidentCreatePage.tsx.
List<MetricOption> get kIncidentKinds => [
  MetricOption(label: trans('uptizm.incidents.form_kind_incident'), value: 'incident'),
  MetricOption(label: trans('uptizm.incidents.form_kind_maintenance'), value: 'maintenance'),
];

/// Operator-side severity tiers offered on the create form. Matches
/// `SEVERITIES` in IncidentCreatePage.tsx.
List<MetricOption> get kIncidentSeverities => [
  MetricOption(label: trans('uptizm.incidents.form_severity_critical'), value: 'critical'),
  MetricOption(label: trans('uptizm.incidents.form_severity_warning'), value: 'warning'),
  MetricOption(label: trans('uptizm.incidents.form_severity_info'), value: 'info'),
];

/// Customer-facing status-page impact levels offered on the create form.
/// Matches `IMPACTS` in IncidentCreatePage.tsx.
List<MetricOption> get kIncidentImpacts => [
  MetricOption(label: trans('uptizm.incidents.form_impact_down'), value: 'down'),
  MetricOption(label: trans('uptizm.incidents.form_impact_degraded'), value: 'degraded'),
  MetricOption(label: trans('uptizm.incidents.form_impact_info'), value: 'info'),
];

/// Lifecycle stages offered in the detail composer's status Select.
///
/// The value is the lifecycle's WIRE token, not its display label. The values
/// used to be hardcoded English title-case strings ('Detected', ...) while the
/// consumer mapped a pick back by comparing against the TRANSLATED enum label,
/// so the two only lined up in English: on a Turkish UI the select showed no
/// current selection and every status pick was silently dropped.
List<MetricOption> get kIncidentStatuses => [
  for (final mocks.IncidentLifecycle stage in mocks.IncidentLifecycle.values)
    MetricOption(
      label: trans('uptizm.incidents.detail_composer_status_${stage.name}'),
      value: stage.name,
    ),
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
/// Branches on [Incident.lifecycle] first (resolved), then on manual/info
/// maintenance, then falls back to the impact-driven investigating copy.
String draftUpdate(Incident i) {
  if (i.lifecycle == mocks.IncidentLifecycle.resolved) {
    return trans('uptizm.incidents.draft_resolved', {'name': i.monitorName});
  }
  if (i.signalSource == mocks.SignalSource.manual &&
      i.impact == mocks.IncidentImpact.info) {
    return trans('uptizm.incidents.draft_maintenance', {'name': i.monitorName});
  }

  final String what = switch (i.impact) {
    mocks.IncidentImpact.down => trans('uptizm.incidents.draft_what_down'),
    mocks.IncidentImpact.degraded =>
      trans('uptizm.incidents.draft_what_degraded'),
    mocks.IncidentImpact.info => trans('uptizm.incidents.draft_what_info'),
  };
  final String signal = i.impact == mocks.IncidentImpact.down
      ? trans('uptizm.incidents.draft_signal_errors')
      : trans('uptizm.incidents.draft_signal_latency');

  return trans('uptizm.incidents.draft_investigating', {
    'what': what,
    'name': i.monitorName,
    'signal': signal,
  });
}

/// A postmortem draft built only from what Uptizm observed. Ports
/// `postmortemDraft` from IncidentDetailPage.tsx.
String postmortemDraft(Incident i) {
  // English pluralizes the noun after a count; Turkish does not, so the two
  // count variants pick the right English wording while sharing one TR value.
  final String monitorWord = trans(
    i.affectedCount == 1
        ? 'uptizm.incidents.postmortem_monitor_one'
        : 'uptizm.incidents.postmortem_monitor_other',
  );
  return trans('uptizm.incidents.postmortem', {
    'title': i.title,
    'duration': i.duration,
    'count': '${i.affectedCount}',
    'monitorWord': monitorWord,
    'signal': i.signalSource.label.toLowerCase(),
  });
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
    for (final Monitor m in monitors) Region(label: m.name ?? '', value: m.id),
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

// The assignee roster lives nowhere in this module on purpose: the detail
// screen's assignee Select reads the team's REAL members from
// `MagicStarterTeamController.members` (`GET /teams/{id}/members`). An invented
// roster here would offer people who do not exist.
