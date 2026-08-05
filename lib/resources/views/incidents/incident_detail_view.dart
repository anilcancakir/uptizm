import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/refetches_on_mount.dart';
import '../../../app/controllers/entitlement_controller.dart';
import '../../../app/support/billing_types.dart' show PlanLimits;
import '../../../app/controllers/incident_controller.dart';
import '../../../app/enums/ai_level.dart' show AiLevel;
import '../../../app/enums/incident_lifecycle.dart'
    show IncidentLifecycle, lifecycleFromWire;
import '../../../app/support/formatters.dart' show formatMonthDayTime;
import '../../../app/support/incident_types.dart'
    show AffectedMonitor, IncidentAcknowledgement, IncidentAi, TimelineEntry;
import '../../../app/models/incident.dart';
import '../../../app/enums/status_key.dart';
import '../../../ui/components/ai_analysis_card/index.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/incident_timeline/index.dart'
    show IncidentTimeline;
import '../../../ui/components/status_badge/index.dart';
import 'incident_form_support.dart';

/// **The Incident Detail screen.**
///
/// The read + write surface for a single incident. It resolves an incident [id]
/// through [IncidentController.incidentById] and renders:
///
/// 1. **Header**: the incident title, a chip row ([StatusBadge] impact +
///    lifecycle / signal-source pills + an AI-owned badge), the monitor / start
///    meta line, and a trailing Resolve / Reopen [Button].
/// 2. **Responder strip** (open incidents only): an "Assigned to" assignee
///    [Select] over the team's REAL members and an Acknowledge [Button] (or the
///    persisted acknowledgement line once someone has acknowledged).
/// 3. **Affected monitors**: each affected monitor with its
///    `statusAtStart -> statusCurrent` transition badges.
/// 4. **AI analysis**: the signature surface, billing-gated: an
///    [AiAnalysisCard] when the current tier unlocks [AiLevel.analysis],
///    otherwise an [MSUpgradeNudge] naming the cheapest plan that does.
/// 5. **Postmortem** (resolved incidents only): the STORED postmortem when one
///    exists (with its published-or-draft state), otherwise the generated
///    [postmortemDraft] in an [AiInsight] banner, plus an editable composer
///    that saves or publishes it.
/// 6. **Timeline**: a Public / All [SegmentedControl] filtering the entries,
///    mapped through [toComponentTimeline] into the [IncidentTimeline], each
///    entry carrying its persisted `author`.
/// 7. **Update composer**: a status [Select], an update [Textarea], a publish
///    [Switch], an "AI draft" [Button] that fills the message with
///    [draftUpdate], and a "Post update" [Button].
///
/// An unresolvable [id] renders a graceful not-found state (mirroring
/// [MonitorDetailView]'s `_buildNotFound`) rather than crashing.
///
/// Every write here is a live [IncidentController] business action against the
/// backend: Resolve / Reopen (`POST /incidents/{id}/resolve` `.../reopen`),
/// Acknowledge (`.../acknowledge`, sending no client-authored identity), Post
/// update (`.../updates`), Assign (`.../assign`) and the postmortem
/// (`.../postmortem`). The AI analysis section is live too: `initState` fires a
/// one-shot [IncidentController.loadAnalysis] (`GET /incidents/{id}/analysis`)
/// and [IncidentController.analysisFor] renders the fast first-paint
/// trigger/confidence/tldr from `GET /incidents/{id}` immediately, enriching
/// with evidence/suggested-actions once the analysis fetch resolves;
/// `similarIncidents` stays empty (deferred).
///
/// Ownership, acknowledgement, and the postmortem are PERSISTED state read back
/// off the incident, never mirrored locally; only the genuinely transient
/// compose state (the lifecycle toggle, the update composer body, and the
/// postmortem editor's open/dirty text) lives in this view. The body is a Wind
/// flex column (`gap-*` carries the section rhythm); the shared [MSPageContainer]
/// bounds the width.
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
  /// `null` or an unknown id renders a graceful not-found [MSEmptyState].
  final String? id;

  /// Creates the [IncidentDetailView] for the given incident [id].
  const IncidentDetailView({super.key, this.id});

  @override
  State<IncidentDetailView> createState() => _IncidentDetailViewState();
}

class _IncidentDetailViewState
    extends MagicStatefulViewState<IncidentController, IncidentDetailView>
    with RefetchesOnMount<IncidentController, IncidentDetailView> {
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

  /// Whether the postmortem editor is open. Transient: the postmortem itself
  /// lives on the incident ([Incident.postmortemBody]).
  bool _editingPostmortem = false;

  /// The postmortem editor's working text while [_editingPostmortem] is set.
  String _postmortemBody = '';

  /// Whether [_postmortemBody] was seeded from the generated draft rather than
  /// a stored body, gating the AI-provenance hint inside the editor.
  bool _postmortemFromDraft = false;

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
    // Load the team's real roster once for the assignee Select. Reuses the
    // starter's own team controller (the single owner of `/teams/{id}/members`)
    // instead of adding a parallel members fetch; it self-guards against a
    // concurrent in-flight load and against a null active team.
    MagicStarterTeamController.instance.loadMembersAndInvitations();
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
  /// Only genuinely transient compose state is seeded here. The assignment, the
  /// acknowledgement, and the postmortem are PERSISTED on the incident
  /// ([Incident.assigneeId], [Incident.acknowledgement],
  /// [Incident.postmortemBody]) and read straight off it at render time, so
  /// there is nothing to seed and nothing that can drift from the backend.
  /// Whether [_seedFrom] has run against a RESOLVED incident.
  ///
  /// The composer's default stage has to come from the incident, but this screen
  /// can mount before the roster carries it (arriving straight from the AI
  /// inbox's "Open incident", or on a direct load). Tracking this lets the
  /// composer reseed once the real incident lands, without ever overwriting a
  /// stage the operator has since picked.
  bool _seededFromIncident = false;

  void _seedFrom(Incident? incident) {
    _view = _viewPublic;
    _message = '';
    _publish = true;
    _aiDrafted = false;
    _editingPostmortem = false;
    _postmortemBody = '';
    _postmortemFromDraft = false;
    if (incident == null) {
      _seededFromIncident = false;
      _lifecycle = IncidentLifecycle.investigating;
      _reopenTo = IncidentLifecycle.investigating;
      return;
    }
    _seededFromIncident = true;
    _lifecycle = incident.lifecycle;
    // If the incident is already resolved, reopening should land on a live stage.
    _reopenTo = incident.lifecycle == IncidentLifecycle.resolved
        ? IncidentLifecycle.investigating
        : incident.lifecycle;
  }

  /// Refetch on every mount: the backing controller loads in `onInit`, which
  /// magic fires only once per controller instance, so opening this screen would
  /// otherwise render whatever the roster held when it was first fetched. A
  /// prefilled form is the sharp edge here, since it writes what it shows back on
  /// save. See [RefetchesOnMount].
  @override
  Future<void> refetch() => controller.reload();

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the incident. A null answer means either "the lookup for this
    //    id has not answered yet" or "no incident has this id", and only the
    //    second is a not-found. The comment below already noted that this screen
    //    can mount before the roster carries the incident; the not-found branch
    //    used to fire in exactly that window, so an operator following the link
    //    in an alert read that the incident did not exist.
    final Incident? incident = controller.incidentById(widget.id);
    if (incident == null) {
      return controller.isFirstLoadFor(widget.id)
          ? _buildPending()
          : _buildNotFound();
    }

    // Reseed the composer's default stage the first time the real incident
    // lands. This screen can mount before the roster carries it (arriving from
    // the AI inbox's "Open incident", or a direct load), and without this the
    // composer would keep offering the placeholder stage.
    if (!_seededFromIncident) {
      _seededFromIncident = true;
      _lifecycle = incident.lifecycle;
      _reopenTo = incident.lifecycle == IncidentLifecycle.resolved
          ? IncidentLifecycle.investigating
          : incident.lifecycle;
    }

    // Read the PERSISTED stage, not the composer's pending choice: picking
    // "Resolved" in the composer used to flip this header to Reopen before
    // anything was posted.
    final bool resolved = incident.lifecycle == IncidentLifecycle.resolved;

    // 2. The page body is a Wind flex column: the outer `gap-6` (24px) sits
    //    between the header block and the body sections; the header block nests
    //    a `gap-4` (16px) between the header and its chip row, and the body
    //    block a `gap-8` (32px) between each section.
    return MSPageContainer(
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
        // The PERSISTED stage, not the composer's pending choice. This read
        // `_lifecycle`, the composer select's local value, so two things went
        // wrong: picking a status in the composer relabelled the header before
        // anything was posted, and an incident that resolved into the roster
        // after this screen mounted kept the seed value. Live, a freshly
        // promoted `detected` incident announced itself as "Investigating".
        _buildPill(incident.lifecycle.label),
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
  /// incident shows "Resolve" and moves to [IncidentLifecycle.resolved]. The
  /// lifecycle flip is optimistic local state; the persistence is the
  /// controller's `POST /incidents/{id}/resolve` (or `/reopen`), which surfaces
  /// its own success/error toast.
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
  /// team's real members and, on the trailing edge, either the persisted
  /// acknowledgement line or an Acknowledge [Button].
  ///
  /// Shown only while the incident is open (the caller gates on `!resolved`);
  /// once resolved, ownership lives in the timeline and postmortem.
  ///
  /// The trailing edge mirrors the backend exactly. `IncidentWriteService`
  /// acknowledges ONLY a still-`detected` incident (anything further along is a
  /// no-op that writes no note), so the button appears only then; a persisted
  /// acknowledgement shows the line instead; and an incident already moved on
  /// without one shows neither, rather than a button whose success toast would
  /// claim a write that never happened.
  Widget _buildResponderStrip(Incident incident) {
    final IncidentAcknowledgement? ack = incident.acknowledgement;
    final bool canAcknowledge =
        ack == null && incident.lifecycle == IncidentLifecycle.detected;

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
            Expanded(child: _buildAssigneeSelect(incident)),
          ],
        ),
        if (ack != null)
          _buildAckLine(ack)
        else if (canAcknowledge)
          _buildAcknowledgeButton(),
      ],
    );
  }

  /// Builds the assignee [Select]: an "Unassigned" sentinel plus the team's
  /// real members.
  ///
  /// The roster comes from [MagicStarterTeamController.members] (the starter's
  /// own `/teams/{id}/members` read, loaded once from `initState`), wrapped in a
  /// [ValueListenableBuilder] so the options appear as soon as that fetch lands.
  /// The rendered value comes from [Incident.assigneeId], never from local
  /// state, so what the Select shows is what the backend stored. A change posts
  /// through [IncidentController.assign] and the reload re-renders from the
  /// persisted incident.
  ///
  /// [Select] carries a controlled non-null value, so the empty-string sentinel
  /// stands in for "no assignee"; it maps to `null` on change. A stored
  /// assignee who is not in the loaded roster yet (the fetch is still in
  /// flight, or they have since left the team) still gets an option of their
  /// own, so the Select never silently renders "Unassigned" over a real owner.
  Widget _buildAssigneeSelect(Incident incident) {
    return ValueListenableBuilder<List<Map<String, dynamic>>>(
      valueListenable: MagicStarterTeamController.instance.members,
      builder: (context, members, _) {
        final String? assigneeId = incident.assigneeId;
        final List<SelectOption<String>> options = [
          SelectOption<String>(
            value: '',
            label: trans('uptizm.incidents.detail_unassigned'),
          ),
          for (final Map<String, dynamic> member in members)
            if (_memberId(member) case final String id)
              SelectOption<String>(value: id, label: _memberName(member, id)),
        ];

        if (assigneeId != null &&
            !options.any((option) => option.value == assigneeId)) {
          options.add(
            SelectOption<String>(
              value: assigneeId,
              label: incident.assigneeName ?? assigneeId,
            ),
          );
        }

        return MSSelect<String>(
          value: assigneeId ?? '',
          options: options,
          onChange: (value) => controller.assign(
            incident,
            (value == null || value.isEmpty) ? null : value,
          ),
        );
      },
    );
  }

  /// The member's id as a string, or `null` when the payload carries none.
  String? _memberId(Map<String, dynamic> member) {
    final String id = member['id']?.toString() ?? '';
    return id.isEmpty ? null : id;
  }

  /// The member's display name, falling back to their email and then their id
  /// so an option is never blank.
  String _memberName(Map<String, dynamic> member, String id) {
    final String name = (member['name'] as String?)?.trim() ?? '';
    if (name.isNotEmpty) return name;

    final String email = (member['email'] as String?)?.trim() ?? '';
    return email.isNotEmpty ? email : id;
  }

  /// Builds the acknowledgement line from the PERSISTED timeline entry: the
  /// author the backend stamped from the acting user, at the time it recorded.
  /// Nothing here is client-authored.
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

  /// Builds the Acknowledge [Button]: asks the backend to record the acting
  /// user as on the incident.
  Widget _buildAcknowledgeButton() {
    return MSButton(
      intent: ButtonIntent.secondary,
      size: ButtonSize.sm,
      onPressed: () => controller.acknowledge(),
      child: WText(trans('uptizm.incidents.detail_acknowledge')),
    );
  }

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
  /// When the team's real tier unlocks [AiLevel.analysis], the full
  /// [AiAnalysisCard] renders; otherwise an [MSUpgradeNudge] names the cheapest
  /// plan that unlocks it. The nudge renders its own headline and upgrade
  /// button, so it is passed the gated-feature message directly (no teaser
  /// wrapping). Wrapped in a [ListenableBuilder] on [EntitlementController] so
  /// it re-gates when the real plan lands, mirroring the backend's own 403 on
  /// `GET /incidents/{id}/analysis` below the analysis tier.
  Widget _buildAiAnalysis(IncidentAi ai) {
    return ListenableBuilder(
      listenable: EntitlementController.instance,
      builder: (context, _) {
        final entitlement = EntitlementController.instance;
        if (entitlement.aiLevelAllows(AiLevel.analysis)) {
          return AiAnalysisCard(
            ai: ai,
            onActionTap: (_) {},
            onFeedback: (_) {},
          );
        }
        bool unlocksAnalysis(PlanLimits limits) =>
            limits.ai.index >= AiLevel.analysis.index;

        return MSUpgradeNudge(
          message: trans('uptizm.incidents.ai_analysis_gated'),
          requiredPlan: entitlement.planNameUnlocking(unlocksAnalysis),
          // Billing with the tier intent, so Upgrade starts the purchase for
          // the plan this nudge just named.
          onUpgrade: () => UpgradePrompt.startUpgrade(
            entitlement.planIdUnlocking(unlocksAnalysis),
          ),
        );
      },
    );
  }

  // ---------------------------------------------------------------------------
  // Postmortem
  // ---------------------------------------------------------------------------

  /// Builds the postmortem section (resolved incidents only), in one of three
  /// states:
  ///
  /// 1. **Editing** ([_editingPostmortem]): the composer, seeded with the stored
  ///    body or, when there is none yet, the generated [postmortemDraft].
  /// 2. **Stored** ([Incident.postmortemBody] present): the persisted body in a
  ///    plain card, with an honest published-or-draft-only state line. A human
  ///    owns this text, so it carries no AI framing.
  /// 3. **Nothing stored**: the generated [postmortemDraft] in an [AiInsight]
  ///    banner, labelled a draft, as the starting point for a human.
  Widget _buildPostmortem(Incident incident) {
    if (_editingPostmortem) {
      return _buildPostmortemEditor(incident);
    }

    final String? stored = incident.postmortemBody;
    if (stored != null) {
      return _buildStoredPostmortem(incident, stored);
    }

    return AiInsight(
      tone: 'banner',
      label: trans('uptizm.incidents.detail_postmortem_heading'),
      action: _buildPostmortemEditButton(incident),
      child: WText(postmortemDraft(incident)),
    );
  }

  /// Builds the stored-postmortem card: the persisted body, its publication
  /// state, and the edit action.
  Widget _buildStoredPostmortem(Incident incident, String body) {
    return MSCard(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-3',
        children: [
          WDiv(
            className: 'wrap items-center justify-between gap-3',
            children: [
              WText(
                trans('uptizm.incidents.detail_postmortem_heading_saved'),
                className: 'text-sm font-semibold text-fg',
              ),
              _buildPostmortemEditButton(incident),
            ],
          ),
          WText(body, className: 'text-sm leading-relaxed text-fg-muted'),
          _buildPostmortemState(incident),
        ],
      ),
    );
  }

  /// Builds the honest publication-state line: published (naming when, from the
  /// backend's own stamp) or an explicit "internal draft, not published".
  Widget _buildPostmortemState(Incident incident) {
    final DateTime? publishedAt = incident.postmortemPublishedAt;
    if (publishedAt == null) {
      return WText(
        trans('uptizm.incidents.detail_postmortem_state_draft'),
        className: 'text-xs font-medium text-degraded-soft-foreground',
      );
    }

    return WText(
      trans('uptizm.incidents.detail_postmortem_state_published', {
        // Numeric, locale-safe timestamp (the shared formatter avoids leaking
        // untranslated English month names), driven by the backend's own stamp.
        'time': formatMonthDayTime(publishedAt),
      }),
      className: 'text-xs font-medium text-up-soft-foreground',
    );
  }

  /// Builds the "Edit & publish" [Button], which opens the composer seeded with
  /// the stored body or the generated draft.
  Widget _buildPostmortemEditButton(Incident incident) {
    return MSButton(
      intent: ButtonIntent.secondary,
      size: ButtonSize.sm,
      onPressed: () => _onEditPostmortem(incident),
      child: WText(trans('uptizm.incidents.detail_postmortem_edit')),
    );
  }

  /// Builds the postmortem composer: the body [Textarea], the AI-provenance hint
  /// while the text is still the generated draft, and the Cancel / Save draft /
  /// Publish actions.
  Widget _buildPostmortemEditor(Incident incident) {
    return MSCard(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-3',
        children: [
          WText(
            trans('uptizm.incidents.detail_postmortem_heading_edit'),
            className: 'text-sm font-semibold text-fg',
          ),
          MSTextarea(
            value: _postmortemBody,
            minLines: 6,
            maxLines: 14,
            placeholder: trans('uptizm.incidents.detail_postmortem_placeholder'),
            onChanged: (value) => setState(() => _postmortemBody = value),
          ),
          // The seeded text is Uptizm's outside-in observation, not a finished
          // analysis; say so for as long as it is still the generated draft.
          if (_postmortemFromDraft)
            AiInsight(
              child: WText(
                trans('uptizm.incidents.detail_postmortem_ai_seeded'),
              ),
            ),
          WDiv(
            className: 'wrap items-center justify-end gap-3',
            children: [
              MSButton(
                intent: ButtonIntent.ghost,
                size: ButtonSize.sm,
                onPressed: () => setState(() => _editingPostmortem = false),
                child: WText(trans('uptizm.incidents.detail_postmortem_cancel')),
              ),
              MSButton(
                intent: ButtonIntent.secondary,
                size: ButtonSize.sm,
                onPressed: _postmortemBody.trim().isEmpty
                    ? null
                    : () => _onSavePostmortem(incident, publish: false),
                child: WText(
                  trans('uptizm.incidents.detail_postmortem_save_draft'),
                ),
              ),
              MSButton(
                size: ButtonSize.sm,
                onPressed: _postmortemBody.trim().isEmpty
                    ? null
                    : () => _onSavePostmortem(incident, publish: true),
                child: WText(
                  trans('uptizm.incidents.detail_postmortem_publish'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Opens the composer, seeded with the stored body when one exists and with
  /// the generated draft otherwise (flagging the AI provenance in that case).
  void _onEditPostmortem(Incident incident) {
    final String? stored = incident.postmortemBody;
    setState(() {
      _editingPostmortem = true;
      _postmortemBody = stored ?? postmortemDraft(incident);
      _postmortemFromDraft = stored == null;
    });
  }

  /// Persists the composer text through [IncidentController.savePostmortem] and
  /// closes the composer only once the write landed, so a failed save keeps the
  /// operator's text on screen instead of discarding it.
  Future<void> _onSavePostmortem(
    Incident incident, {
    required bool publish,
  }) async {
    final bool saved = await controller.savePostmortem(
      incident,
      _postmortemBody,
      publish: publish,
    );
    if (!saved || !mounted) return;

    setState(() {
      _editingPostmortem = false;
      _postmortemFromDraft = false;
    });
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
      // Keyed by the lifecycle's WIRE token, never its display label: the
      // options carried English title-case values while this mapped a pick back
      // through the translated label, so on a Turkish UI the select showed no
      // selection and every pick was dropped.
      value: _lifecycle.name,
      options: kIncidentStatuses
          .map((o) => SelectOption<String>(value: o.value, label: o.label))
          .toList(),
      onChange: (value) {
        if (value == null) return;

        setState(() => _lifecycle = lifecycleFromWire(value));
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
    controller.postUpdate(
      incident,
      message: message,
      isPublic: _publish,
      status: _lifecycle,
    );
  }

  /// Maps a title-case status [label] (e.g. `"Investigating"`) back to its

  // ---------------------------------------------------------------------------
  // Not-found
  // ---------------------------------------------------------------------------

  /// Builds the graceful not-found state shown when [findIncident] returns null.
  ///
  /// Reuses the incidents error-load copy as a calm "couldn't load this
  /// incident" message rather than crashing on an unknown route id.
  /// Builds the pending state shown while the lookup that will decide whether
  /// this incident exists is still in flight.
  ///
  /// Every [MSSkeleton] carries an explicit height: it wraps a childless `WDiv`
  /// and has nothing of its own to measure, so one without a height lays out 0px
  /// tall and the operator sees a blank screen instead of a placeholder.
  Widget _buildPending() {
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('common.loading'),
            backLabel: trans('uptizm.incidents.detail_back'),
            backFallback: '/incidents',
          ),
          WDiv(
            className: 'flex flex-col gap-4',
            children: const [
              MSSkeleton(height: 96),
              MSSkeleton(height: 160),
              MSSkeleton(height: 160),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildNotFound() {
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('uptizm.incidents.error_load_title'),
            backLabel: trans('uptizm.incidents.detail_back'),
            backFallback: '/incidents',
          ),
          MSEmptyState(
            title: trans('uptizm.incidents.error_load_title'),
            description: trans('uptizm.incidents.error_load_description'),
          ),
        ],
      ),
    );
  }
}
