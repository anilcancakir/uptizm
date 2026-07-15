import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/incident_controller.dart';
import '../../../app/enums/ai_level.dart' show AiLevel;
import '../../../app/mocks/billing.dart';
import '../../../app/enums/incident_lifecycle.dart' show IncidentLifecycle;
import '../../../app/support/incident_types.dart'
    show
        AffectedMonitor,
        IncidentAcknowledgement,
        IncidentAi,
        IncidentAssignee,
        TimelineEntry;
import '../../../app/mocks/incidents.dart';
import '../../../app/models/incident.dart';
import '../../../app/enums/status_key.dart';
import '../../../ui/components/ai_analysis_card/index.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/empty_state/index.dart';
import '../../../ui/components/incident_timeline/index.dart'
    show IncidentTimeline;
import '../../../ui/components/status_badge/index.dart';
import '../../../ui/components/upgrade_nudge/index.dart';
import '../../../ui/layouts/page_container.dart';
import 'incident_form_support.dart';

/// **The Incident Detail screen.**
///
/// A faithful Flutter port of the React `IncidentDetailPage`: the read + write
/// surface for a single incident. It resolves an incident [id] to a fixture via
/// [findIncident] and renders, in the React section order:
///
/// 1. **Header**: the incident title, a chip row ([StatusBadge] impact +
///    lifecycle / signal-source pills + an AI-owned badge), the monitor / start
///    meta line, and a trailing Resolve / Reopen [Button].
/// 2. **Responder strip** (open incidents only): an "Assigned to" assignee
///    [Select] over the [responders] roster and an Acknowledge [Button].
/// 3. **Affected monitors**: each affected monitor with its
///    `statusAtStart -> statusCurrent` transition badges.
/// 4. **AI analysis**: the signature surface, billing-gated: an
///    [AiAnalysisCard] when the current tier unlocks [AiLevel.analysis],
///    otherwise an [UpgradeNudge] naming the cheapest plan that does.
/// 5. **Postmortem** (resolved incidents only): an [AiInsight] banner carrying
///    the [postmortemDraft].
/// 6. **Timeline**: a Public / All [SegmentedControl] filtering the entries,
///    mapped through [toComponentTimeline] into the [IncidentTimeline].
/// 7. **Update composer**: a status [Select], an update [Textarea], a publish
///    [Switch], an "AI draft" [Button] that fills the message with
///    [draftUpdate], and a "Post update" [Button].
///
/// When [findIncident] returns `null` it renders a graceful not-found state
/// (mirroring [MonitorDetailView]'s `_buildNotFound`) rather than crashing when
/// the route passes an id with no fixture behind it.
///
/// Resolve / Reopen, Acknowledge, and Post update are live [IncidentController]
/// business actions against the backend (`POST /incidents/{id}/resolve`
/// `.../reopen` `.../acknowledge` `.../updates`); Assign and postmortem-edit
/// stay local mocks (there is no assignee-write or postmortem-write endpoint
/// yet). The AI analysis section is live too: `initState` fires a one-shot
/// [IncidentController.loadAnalysis] (`GET /incidents/{id}/analysis`) and
/// [IncidentController.analysisFor] renders the fast first-paint
/// trigger/confidence/tldr from `GET /incidents/{id}` immediately, enriching
/// with evidence/suggested-actions once the analysis fetch resolves;
/// `similarIncidents` stays empty (deferred). The transient compose state
/// (lifecycle, assignee, composer body) stays local to this view regardless.
/// The body is a Wind flex column (`gap-*` carries the section rhythm); the
/// shared [PageContainer] bounds the width.
///
/// ### Example
/// ```dart
/// // Registered as the routed `/incidents/:id` content (wrapped by the shell):
/// MagicStarter.view.makeLayout(
///   'layout.app',
///   child: const IncidentDetailView(id: 'checkout-503'),
/// )
/// ```
@immutable
class IncidentDetailView extends MagicStatefulView<IncidentController> {
  /// The incident identifier resolved against the fixtures via
  /// [IncidentController.incidentById].
  ///
  /// `null` or an unknown id renders a graceful not-found [EmptyState].
  final String? id;

  /// Creates the [IncidentDetailView] for the given incident [id].
  const IncidentDetailView({super.key, this.id});

  @override
  State<IncidentDetailView> createState() => _IncidentDetailViewState();
}

class _IncidentDetailViewState
    extends MagicStatefulViewState<IncidentController, IncidentDetailView> {
  /// The timeline view: `'public'` (subscriber-visible entries only) or `'all'`
  /// (the full activity log). Defaults to public, matching the React source.
  String _view = _viewPublic;

  /// The current lifecycle stage. Seeded from the resolved incident and driven
  /// by the Resolve / Reopen toggle and the composer's status [Select].
  late IncidentLifecycle _lifecycle;

  /// The lifecycle to restore when reopening a resolved incident: the stage the
  /// incident sat at before it was resolved. Seeded to [IncidentLifecycle.investigating]
  /// so a freshly-resolved fixture reopens to a sane in-progress stage.
  IncidentLifecycle _reopenTo = IncidentLifecycle.investigating;

  /// The composer message body (React `message`).
  String _message = '';

  /// Whether the next update publishes to the public status page (React
  /// `publish`, default `true`).
  bool _publish = true;

  /// Whether the current composer message was drafted by AI, gating the
  /// "drafted by AI" [AiInsight] hint (React `aiDrafted`).
  bool _aiDrafted = false;

  /// The selected assignee name, or `null` for Unassigned (React `assigneeId`).
  String? _assigneeName;

  /// The acknowledgement record, or `null` when not yet acknowledged (React
  /// `ack`).
  IncidentAcknowledgement? _ack;

  /// The public timeline view value.
  static const String _viewPublic = 'public';

  /// The all-activity timeline view value.
  static const String _viewAll = 'all';

  @override
  void initState() {
    // Register the controller before the base resolves it via `Magic.find<T>()`
    // (which throws if unregistered); see Conventions -> Controller binding.
    Magic.findOrPut(IncidentController.new);
    super.initState();
    // One-shot fetch of the enriched AI analysis (evidence + suggested
    // actions); never called from `build`, so navigating away and back
    // re-fetches exactly once per mount instead of on every rebuild. A
    // `null` id has no analysis to fetch.
    if (widget.id != null) {
      controller.loadAnalysis(widget.id!);
    }
  }

  @override
  void onInit() {
    // Seed the local compose state once the controller is resolved. `onInit`
    // runs inside the base `initState`, before the first build, so the fields
    // are assigned directly rather than through `setState`.
    _seedFrom(controller.incidentById(widget.id));
  }

  @override
  void didUpdateWidget(covariant IncidentDetailView oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Reseed the local state whenever the resolved incident changes so
    // navigating between incidents does not carry stale composer / lifecycle
    // state across.
    if (oldWidget.id != widget.id) {
      _seedFrom(controller.incidentById(widget.id));
    }
  }

  /// Seeds the local mutable state from [incident] (or resets it when null).
  ///
  /// Runs from [initState] and [didUpdateWidget]; both schedule their own build,
  /// so state is assigned directly rather than through [setState].
  ///
  /// [_assigneeName] and [_ack] always start empty: the [Incident] ORM model
  /// carries no assignee/acknowledgement (the backend `IncidentResource` has
  /// no counterpart for them, so an `Incident.fromMap` never hydrates one).
  /// Assignment and acknowledgement are wired locally from here (the responder
  /// strip + the Acknowledge button), matching the previous behaviour on a
  /// backend-decoded incident, which also had both fields null.
  void _seedFrom(Incident? incident) {
    _view = _viewPublic;
    _message = '';
    _publish = true;
    _aiDrafted = false;
    _assigneeName = null;
    _ack = null;
    if (incident == null) {
      _lifecycle = IncidentLifecycle.investigating;
      _reopenTo = IncidentLifecycle.investigating;
      return;
    }
    _lifecycle = incident.lifecycle;
    // If the incident is already resolved, reopening should land on a live stage.
    _reopenTo = incident.lifecycle == IncidentLifecycle.resolved
        ? IncidentLifecycle.investigating
        : incident.lifecycle;
  }

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the incident; a null / unknown id falls back to a graceful
    //    not-found state so the screen never crashes on an unknown route id.
    final Incident? incident = controller.incidentById(widget.id);
    if (incident == null) {
      return _buildNotFound();
    }

    final bool resolved = _lifecycle == IncidentLifecycle.resolved;

    // 2. The page body is a Wind flex column: the outer `gap-6` (24px) sits
    //    between the header block and the body sections; the header block nests
    //    a `gap-4` (16px) between the header and its chip row, and the body
    //    block a `gap-8` (32px) between each section.
    return PageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          // 3. Header block: title + meta + Resolve/Reopen, then a full-width
          //    chip row below it (16px rhythm). The chips are NOT a PageHeader
          //    titleSuffix: that slot is wrapped in a `flex-shrink-0` WDiv
          //    (page_header.dart), which Wind excludes from Flexible-wrapping,
          //    so a 4-pill row there overflows the half-width title slot. As a
          //    standalone `wrap` row below the header it flows onto a 2nd line.
          WDiv(
            className: 'flex flex-col gap-4',
            children: [
              MSPageHeader(
                title: incident.title,
                subtitle: '${incident.monitorName} · ${incident.startedAt}',
                backLabel: trans('uptizm.incidents.detail_back'),
                backFallback: '/incidents',
                actions: [_buildResolveButton(incident, resolved)],
              ),
              _buildChipRow(incident),
            ],
          ),

          // 4. Body sections, each separated by the 32px `gap-8` rhythm:
          //    responder strip (open only), affected monitors, AI analysis
          //    (when present), postmortem (resolved only), timeline, composer.
          WDiv(
            className: 'flex flex-col gap-8',
            children: [
              if (!resolved) _buildResponderStrip(incident),
              _buildAffectedMonitors(incident),
              if (controller.analysisFor(incident) case final ai?)
                _buildAiAnalysis(ai),
              if (resolved) _buildPostmortem(incident),
              _buildTimeline(incident),
              _buildComposer(incident),
            ],
          ),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Header
  // ---------------------------------------------------------------------------

  /// Builds the header chip row: the impact [StatusBadge], the lifecycle and
  /// signal-source pills, and an AI-owned [StatusBadge] when [aiOwned].
  ///
  /// Uses `wrap` so the chips flow onto a second line on a narrow phone instead
  /// of overflowing the header title row.
  Widget _buildChipRow(Incident incident) {
    return WDiv(
      className: 'wrap items-center gap-2',
      children: [
        StatusBadge(incident.impact.statusKey),
        _buildPill(_lifecycle.label),
        _buildPill(incident.signalSource.label),
        // The React source labels this "AI-owned"; no i18n key exists for that
        // copy, so the badge falls back to its own `uptizm.status.ai` label.
        if (incident.aiOwned) StatusBadge(StatusKey.ai),
      ],
    );
  }

  /// Builds a bordered outline pill (the React `Badge tone="outline"`): a
  /// token-only rounded chip carrying [text].
  Widget _buildPill(String text) {
    return WDiv(
      className:
          'flex flex-row items-center rounded-full border '
          'border-color-border px-2.5 py-0.5',
      child: WText(text, className: 'text-xs font-medium text-fg-muted'),
    );
  }

  /// Builds the trailing Resolve / Reopen [Button].
  ///
  /// A resolved incident shows "Reopen" and restores [_reopenTo]; an open
  /// incident shows "Resolve" and moves to [IncidentLifecycle.resolved]. Either
  /// way the toggle is local state plus a `Magic.success` toast.
  Widget _buildResolveButton(Incident incident, bool resolved) {
    return MSButton(
      size: ButtonSize.sm,
      onPressed: () => _onResolveReopen(incident, resolved),
      child: WText(
        resolved
            ? trans('uptizm.incidents.detail_reopen')
            : trans('uptizm.incidents.detail_resolve'),
      ),
    );
  }

  /// Toggles the incident between resolved and its previous live stage, then
  /// surfaces a toast. Reopening restores [_reopenTo]; resolving remembers the
  /// current stage first so a later reopen lands back on it.
  void _onResolveReopen(Incident incident, bool resolved) {
    setState(() {
      if (resolved) {
        _lifecycle = _reopenTo;
      } else {
        _reopenTo = _lifecycle;
        _lifecycle = IncidentLifecycle.resolved;
      }
    });
    // The lifecycle flip above is ephemeral compose state owned by this view;
    // the toast is the controller business action (the action label is the
    // toast title and the incident title the body, matching the React mock).
    if (resolved) {
      controller.reopen(incident);
    } else {
      controller.resolve(incident);
    }
  }

  // ---------------------------------------------------------------------------
  // Responder strip
  // ---------------------------------------------------------------------------

  /// Builds the responder strip: an "Assigned to" assignee [Select] over the
  /// [responders] roster and, on the trailing edge, either the acknowledgement
  /// line or an Acknowledge [Button].
  ///
  /// Shown only while the incident is open (the caller gates on `!resolved`);
  /// once resolved, ownership lives in the timeline and postmortem.
  Widget _buildResponderStrip(Incident incident) {
    return WDiv(
      className:
          'flex flex-col gap-3 rounded-lg border border-color-border '
          'bg-surface-container p-4 sm:flex-row sm:items-center '
          'sm:justify-between',
      children: [
        WDiv(
          className: 'flex flex-row items-center gap-3 min-w-0',
          children: [
            WText(
              trans('uptizm.incidents.detail_assigned_to'),
              className:
                  'text-xs font-medium uppercase tracking-wide text-fg-muted',
            ),
            Expanded(child: _buildAssigneeSelect()),
          ],
        ),
        if (_ack != null) _buildAckLine(_ack!) else _buildAcknowledgeButton(),
      ],
    );
  }

  /// Builds the assignee [Select]: an "Unassigned" sentinel plus the roster.
  ///
  /// [Select] carries a controlled non-null value, so the empty-string sentinel
  /// stands in for "no assignee"; it maps to `null` on change.
  Widget _buildAssigneeSelect() {
    return MSSelect<String>(
      value: _assigneeName ?? '',
      options: [
        SelectOption<String>(
          value: '',
          label: trans('uptizm.incidents.detail_unassigned'),
        ),
        for (final IncidentAssignee r in responders)
          SelectOption<String>(value: r.name, label: r.name),
      ],
      onChange: (value) {
        setState(
          () => _assigneeName = (value == null || value.isEmpty) ? null : value,
        );
      },
    );
  }

  /// Builds the acknowledgement line shown once someone is on the incident.
  Widget _buildAckLine(IncidentAcknowledgement ack) {
    return WDiv(
      className: 'flex flex-row items-center gap-1.5',
      children: [
        WIcon(Icons.check, className: 'text-up-soft-foreground text-base'),
        WText(
          trans('uptizm.incidents.detail_acknowledged', {
            'name': ack.by,
            'time': ack.at,
          }),
          className: 'text-sm font-medium text-up-soft-foreground',
        ),
      ],
    );
  }

  /// Builds the Acknowledge [Button]: records the current user as on the
  /// incident and surfaces the acknowledgement toast.
  Widget _buildAcknowledgeButton() {
    return MSButton(
      intent: ButtonIntent.secondary,
      size: ButtonSize.sm,
      onPressed: _onAcknowledge,
      child: WText(trans('uptizm.incidents.detail_acknowledge')),
    );
  }

  /// Records the acknowledgement locally and surfaces the toast. The current
  /// user is the fixed mock responder [_currentUserName]; the time is "just now"
  /// (mirroring the React source's inline `{ by: currentUser.name, at: "just now" }`).
  void _onAcknowledge() {
    const String by = _currentUserName;
    setState(() {
      _ack = const IncidentAcknowledgement(by: by, at: 'just now');
    });
    controller.acknowledge(by);
  }

  /// The current mock responder who acknowledges an incident. The React source
  /// reads `currentUser.name` from a teams fixture; no such fixture is wired
  /// into uptizm, so the roster lead stands in as the acting user.
  static const String _currentUserName = 'Ada Lovelace';

  // ---------------------------------------------------------------------------
  // Affected monitors
  // ---------------------------------------------------------------------------

  /// Builds the affected-monitors section: a heading with the affected count and
  /// a bordered list of each affected monitor with its `statusAtStart →
  /// statusCurrent` transition (the arrow only appears when the two differ).
  Widget _buildAffectedMonitors(Incident incident) {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WText(
          trans('uptizm.incidents.detail_affected_monitors_label'),
          className: 'text-sm font-semibold text-fg',
        ),
        WDiv(
          className: 'flex flex-col rounded-lg border border-color-border',
          children: [
            for (var i = 0; i < incident.affectedMonitors.length; i++)
              _buildAffectedRow(incident.affectedMonitors[i], isFirst: i == 0),
          ],
        ),
      ],
    );
  }

  /// Builds one affected-monitor row: name on the left, status transition on the
  /// right. A hairline top border separates every row after the first.
  Widget _buildAffectedRow(AffectedMonitor monitor, {required bool isFirst}) {
    final bool changed = monitor.statusAtStart != monitor.statusCurrent;
    return WDiv(
      className: isFirst
          ? 'flex flex-row items-center justify-between gap-3 px-4 py-3'
          : 'flex flex-row items-center justify-between gap-3 px-4 py-3 '
                'border-t border-color-border',
      children: [
        Expanded(
          child: WText(monitor.name, className: 'text-sm font-medium text-fg'),
        ),
        WDiv(
          className: 'flex flex-row items-center gap-1.5',
          children: [
            if (changed) ...[
              StatusBadge(monitor.statusAtStart),
              WText('→', className: 'text-fg-muted text-sm'),
            ],
            StatusBadge(monitor.statusCurrent),
          ],
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // AI analysis
  // ---------------------------------------------------------------------------

  /// Builds the AI analysis section, billing-gated.
  ///
  /// When the current tier's AI capability reaches [AiLevel.analysis], the full
  /// [AiAnalysisCard] renders; otherwise an [UpgradeNudge] names the cheapest
  /// plan that unlocks it. The nudge renders its own headline and upgrade
  /// button, so it is passed the gated-feature message directly (no teaser
  /// wrapping).
  Widget _buildAiAnalysis(IncidentAi ai) {
    final bool unlocked = currentLimits.ai.index >= AiLevel.analysis.index;
    if (unlocked) {
      return AiAnalysisCard(ai: ai, onActionTap: (_) {}, onFeedback: (_) {});
    }
    return UpgradeNudge(
      message: trans('uptizm.incidents.ai_analysis_gated'),
      requiredPlan: planForAiAnalysis().name,
      onUpgrade: () => MagicRoute.to('/settings'),
    );
  }

  // ---------------------------------------------------------------------------
  // Postmortem
  // ---------------------------------------------------------------------------

  /// Builds the postmortem section (resolved incidents only): an [AiInsight]
  /// banner carrying the [postmortemDraft] with an "Edit & publish" action.
  Widget _buildPostmortem(Incident incident) {
    return AiInsight(
      tone: 'banner',
      label: trans('uptizm.incidents.detail_postmortem_heading'),
      action: MSButton(
        intent: ButtonIntent.secondary,
        size: ButtonSize.sm,
        onPressed: _onEditPostmortem,
        child: WText(trans('uptizm.incidents.detail_postmortem_edit')),
      ),
      child: WText(postmortemDraft(incident)),
    );
  }

  /// Surfaces the postmortem-edit toast. Local mock: the draft is not persisted.
  void _onEditPostmortem() {
    controller.editPostmortem();
  }

  // ---------------------------------------------------------------------------
  // Timeline
  // ---------------------------------------------------------------------------

  /// Builds the timeline section: a heading row with a Public / All
  /// [SegmentedControl], then the filtered [IncidentTimeline] (or an empty note
  /// when the current view yields no entries).
  ///
  /// The mocks-layer entries are mapped through [toComponentTimeline] into the
  /// timeline component's own entry type (the two types intentionally stay
  /// separate; the mapper is the bridge).
  Widget _buildTimeline(Incident incident) {
    final List<TimelineEntry> filtered = _view == _viewPublic
        ? incident.timeline.where((e) => e.isPublic).toList()
        : incident.timeline;

    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        // The React `h2` "Timeline" heading has no i18n key (only the two
        // segment labels and the empty note ship), so the self-describing
        // Public / All SegmentedControl stands as the section's own label
        // rather than fabricating an untranslated heading.
        WDiv(
          className: 'wrap items-center gap-3',
          children: [
            MSSegmentedControl<String>(
              size: SegmentedControlSize.sm,
              options: [
                trans('uptizm.incidents.detail_timeline_public'),
                trans('uptizm.incidents.detail_timeline_all'),
              ],
              selectedIndex: _view == _viewPublic ? 0 : 1,
              onChanged: (index) =>
                  setState(() => _view = index == 0 ? _viewPublic : _viewAll),
            ),
          ],
        ),
        if (filtered.isNotEmpty)
          IncidentTimeline(entries: toComponentTimeline(filtered))
        else
          _buildTimelineEmpty(),
      ],
    );
  }

  /// Builds the dashed empty note shown when the current timeline view has no
  /// entries (typically the public view before any public update is posted).
  Widget _buildTimelineEmpty() {
    return WDiv(
      className:
          'rounded-lg border border-dashed border-color-border '
          'px-4 py-6',
      child: WText(
        trans('uptizm.incidents.detail_timeline_empty'),
        className: 'text-center text-sm text-fg-muted',
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Update composer
  // ---------------------------------------------------------------------------

  /// Builds the update composer: a heading row with the "AI draft" [Button], the
  /// status [Select], the message [Textarea], an optional "drafted by AI"
  /// [AiInsight] hint, and a footer with the publish [Switch] and the "Post
  /// update" [Button].
  Widget _buildComposer(Incident incident) {
    return MSCard(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-3',
        children: [
          // 1. Heading + AI-draft action.
          WDiv(
            className: 'flex flex-row items-center justify-between gap-3',
            children: [
              WText(
                trans('uptizm.incidents.detail_composer_heading'),
                className: 'text-sm font-semibold text-fg',
              ),
              MSButton(
                intent: ButtonIntent.secondary,
                size: ButtonSize.sm,
                onPressed: () => _onAiDraft(incident),
                child: WText(
                  trans('uptizm.incidents.detail_composer_ai_draft'),
                ),
              ),
            ],
          ),

          // 2. Status select (title-case values mirror IncidentLifecycle.label).
          _buildStatusSelect(),

          // 3. The update body.
          MSTextarea(
            value: _message,
            minLines: 3,
            maxLines: 6,
            placeholder: trans('uptizm.incidents.detail_composer_placeholder'),
            onChanged: (value) => setState(() {
              _message = value;
              _aiDrafted = false;
            }),
          ),

          // 4. AI-drafted hint (only right after an AI draft).
          if (_aiDrafted)
            AiInsight(
              child: WText(
                trans('uptizm.incidents.detail_composer_ai_insight'),
              ),
            ),

          // 5. Footer: publish switch (left) + Post update (right).
          WDiv(
            className: 'wrap items-center justify-between gap-3',
            children: [
              _buildPublishSwitch(),
              MSButton(
                onPressed: _message.trim().isEmpty
                    ? null
                    : () => _onPostUpdate(incident),
                child: WText(trans('uptizm.incidents.detail_composer_post')),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Builds the composer's status [Select], bound to [_lifecycle].
  ///
  /// [kIncidentStatuses] carries title-case string values matching
  /// [IncidentLifecycle.label]; the Select rides on the current lifecycle's
  /// label and maps the chosen label back to the enum via [_lifecycleForLabel].
  Widget _buildStatusSelect() {
    return MSSelect<String>(
      value: _lifecycle.label,
      options: kIncidentStatuses
          .map((o) => SelectOption<String>(value: o.value, label: o.label))
          .toList(),
      onChange: (value) {
        if (value == null) return;
        final IncidentLifecycle? next = _lifecycleForLabel(value);
        if (next != null) setState(() => _lifecycle = next);
      },
    );
  }

  /// Builds the publish [Switch] row: the toggle followed by its label.
  Widget _buildPublishSwitch() {
    final String label = trans(
      'uptizm.incidents.detail_composer_publish_label',
    );
    return WDiv(
      className: 'flex flex-row items-center gap-3 min-w-0',
      children: [
        MSSwitch(
          value: _publish,
          onChanged: (value) => setState(() => _publish = value),
          semanticLabel: label,
        ),
        WText(label, className: 'min-w-0 text-sm text-fg'),
      ],
    );
  }

  /// Fills the composer with an AI-generated draft and flags the drafted hint.
  void _onAiDraft(Incident incident) {
    setState(() {
      _message = draftUpdate(incident);
      _aiDrafted = true;
    });
  }

  /// Posts the composer text via [IncidentController.postUpdate] (`POST
  /// /incidents/{id}/updates`), then clears the composer. The submit button
  /// is disabled while the composer is blank, so [_message] is always
  /// non-empty here; it is captured before the composer clears so the
  /// controller still receives the real text.
  void _onPostUpdate(Incident incident) {
    final String message = _message.trim();
    setState(() {
      _message = '';
      _aiDrafted = false;
    });
    controller.postUpdate(incident, message);
  }

  /// Maps a title-case status [label] (e.g. `"Investigating"`) back to its
  /// [IncidentLifecycle], or `null` when no stage matches.
  IncidentLifecycle? _lifecycleForLabel(String label) {
    for (final IncidentLifecycle stage in IncidentLifecycle.values) {
      if (stage.label == label) return stage;
    }
    return null;
  }

  // ---------------------------------------------------------------------------
  // Not-found
  // ---------------------------------------------------------------------------

  /// Builds the graceful not-found state shown when [findIncident] returns null.
  ///
  /// Reuses the incidents error-load copy as a calm "couldn't load this
  /// incident" message rather than crashing on an unknown route id.
  Widget _buildNotFound() {
    return PageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('uptizm.incidents.error_load_title'),
            backLabel: trans('uptizm.incidents.detail_back'),
            backFallback: '/incidents',
          ),
          EmptyState(
            title: trans('uptizm.incidents.error_load_title'),
            description: trans('uptizm.incidents.error_load_description'),
          ),
        ],
      ),
    );
  }
}
