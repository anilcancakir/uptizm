import 'dart:async' show unawaited;

import 'package:flutter/material.dart' show CircularProgressIndicator, Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/refetches_on_mount.dart';
import '../../../app/controllers/entitlement_controller.dart';
import '../../../app/controllers/on_call_controller.dart';
import '../../../app/enums/status_key.dart';
import '../../../app/models/on_call_schedule.dart';
import '../../../app/support/billing_types.dart' show PlanLimits;
import '../../../app/support/formatters.dart' show formatMonthDayTime;
import '../../../app/support/team_types.dart'
    show
        OnCallOverrideWindow,
        OnCallResponder,
        OnCallRotationSlot,
        TeamResponder;
import '../../../ui/components/status_dot/index.dart';

/// **On-call schedule screen (`/teams/on-call`).**
///
/// Who is holding the pager right now, the rotation ring behind it, the live
/// overrides, and the controls to change all three. Every rendered person and
/// every rendered time comes from the API through [OnCallController]; there is
/// no fixture anywhere in this file, and nothing on screen is derived from
/// client-side rotation math.
///
/// - **Phases**: the body renders the controller's [OnCallPhase]. `loading` is
///   a spinner, `error` is an [MSErrorState] with a retry (never an empty
///   state: a failed read must not read as "nobody is on call"), `empty` is an
///   [MSEmptyState] offering to create the team's first schedule, `ready` is
///   the screen below.
/// - **Hero card**: the responder `GET /on-call/current` resolved. When the
///   backend resolves nobody (an empty ring with no covering override), the
///   card says so in a neutral [StatusKey.paused] tone instead of promoting a
///   placeholder person.
/// - **Rotation card**: one row per [OnCallRotationSlot], carrying the server's
///   `user_name` and `shift_hours`, with reorder / remove controls. An empty
///   ring renders its own empty state.
/// - **Overrides card**: rendered only when the schedule actually has
///   overrides; each row can be lifted (`DELETE .../overrides/:id`).
/// - **Member picker**: [MagicStarterTeamController.members], the real team
///   roster, minus whoever is already in the ring. Once the plan's responder
///   cap fills the ring it becomes an [MSUpgradeNudge] naming the cheapest plan
///   that lifts the cap.
///
/// Every write (create schedule, add to rotation, remove, reorder, add
/// override, lift override) goes through the controller, which re-reads the API
/// afterwards, so what is on screen after a write is what the API would answer.
///
/// ### Example
/// ```dart
/// MagicRoute.page('/teams/on-call', () => const OnCallScheduleView());
/// ```
@immutable
class OnCallScheduleView extends MagicStatefulView<OnCallController> {
  /// Creates the [OnCallScheduleView].
  const OnCallScheduleView({super.key});

  @override
  State<OnCallScheduleView> createState() => _OnCallScheduleViewState();
}

class _OnCallScheduleViewState
    extends MagicStatefulViewState<OnCallController, OnCallScheduleView>
    with RefetchesOnMount<OnCallController, OnCallScheduleView> {
  /// The move-earlier glyph on a rotation row.
  static const IconData _moveUpIcon = Icons.arrow_upward;

  /// The move-later glyph on a rotation row.
  static const IconData _moveDownIcon = Icons.arrow_downward;

  /// The remove-responder glyph on a rotation row (matches the escalation
  /// editor's rung control).
  static const IconData _removeIcon = Icons.delete_outline;

  /// The starter's team controller, the single client-side source of the real
  /// member roster. Never a fixture, and never a parallel fetch of our own.
  final MagicStarterTeamController _team = MagicStarterTeamController.instance;

  @override
  void initState() {
    // Register the on-call controller before the base state resolves it via
    // Magic.find<T>() (which throws when unregistered). Idempotent.
    Magic.findOrPut(OnCallController.new);
    super.initState();
    // Warm the real member roster for the picker. A no-op when no team is
    // active yet, and it publishes through its own ValueNotifier, so the
    // picker fills in as soon as the roster lands.
    unawaited(_team.loadMembersAndInvitations());
  }

  /// Refetch on every mount: the backing controller loads in `onInit`, which
  /// magic fires only once per controller instance, so re-entering this route
  /// would otherwise re-render the data fetched the first time it was ever
  /// opened. See [RefetchesOnMount].
  @override
  Future<void> refetch() => controller.ensureFresh();

  @override
  Widget build(BuildContext context) {
    return MSPageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          MSPageHeader(
            title: trans('uptizm.teams.oncall_title'),
            subtitle: trans('uptizm.teams.oncall_description'),
            backLabel: trans('uptizm.status.editor_breadcrumb_back'),
            backFallback: '/',
            actions: [
              if (controller.phase == OnCallPhase.ready)
                _buildOverrideControl(),
            ],
          ),
          const SizedBox(height: 24),
          _buildBody(),
        ],
      ),
    );
  }

  /// Renders the body for the controller's current phase.
  Widget _buildBody() {
    switch (controller.phase) {
      case OnCallPhase.loading:
        return const WDiv(
          className: 'py-16 flex items-center justify-center',
          child: CircularProgressIndicator(),
        );
      case OnCallPhase.error:
        return MSErrorState(
          title: trans('uptizm.teams.oncall_load_error_title'),
          description: trans('uptizm.teams.oncall_load_error_description'),
          action: MSButton(
            intent: ButtonIntent.secondary,
            size: ButtonSize.sm,
            onPressed: controller.reload,
            child: WText(trans('uptizm.common.retry')),
          ),
        );
      case OnCallPhase.empty:
        return _buildScheduleEmptyState();
      case OnCallPhase.ready:
        return _buildSchedule();
    }
  }

  // ---------------------------------------------------------------------------
  // Empty state: the team has no schedule at all
  // ---------------------------------------------------------------------------

  /// Builds the "no schedule yet" [MSEmptyState] with a create action.
  ///
  /// This is what a team with no rotation sees: an honest offer to create one,
  /// never a rotation of people who were never configured.
  Widget _buildScheduleEmptyState() {
    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: MSEmptyState(
        icon: Icons.notifications_active_outlined,
        title: trans('uptizm.teams.oncall_empty_title'),
        description: trans('uptizm.teams.oncall_empty_description'),
        action: MSButton(
          onPressed: controller.createSchedule,
          child: WText(trans('uptizm.teams.oncall_create_button')),
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Loaded schedule
  // ---------------------------------------------------------------------------

  /// Builds the loaded schedule: hero card, rotation section, overrides
  /// section, and the escalation footer line.
  Widget _buildSchedule() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _buildHeroCard(),
        const SizedBox(height: 24),
        _buildRotationSection(),
        if (controller.overrides.isNotEmpty) ...[
          const SizedBox(height: 24),
          _buildOverridesSection(),
        ],
        const SizedBox(height: 24),
        WText(
          trans('uptizm.teams.oncall_escalation_reference'),
          className: 'text-sm text-fg-muted',
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Override control (page header action)
  // ---------------------------------------------------------------------------

  /// Builds the "Override" [MSDropdownMenu] listing the real team members.
  ///
  /// Tapping an entry hands that member the pager for a 24-hour window; the
  /// backend then resolves them as the current responder on the next read.
  /// Renders nothing while the roster is empty (a picker of nobody is worse
  /// than no picker).
  Widget _buildOverrideControl() {
    return ValueListenableBuilder<List<Map<String, dynamic>>>(
      valueListenable: _team.members,
      builder: (context, members, _) {
        final List<TeamResponder> responders = _respondersFrom(members);
        if (responders.isEmpty) return const SizedBox.shrink();

        return MSDropdownMenu(
          items: [
            for (final TeamResponder member in responders)
              MSDropdownMenuItem(
                label: member.name,
                leading: _buildAvatarTile(
                  member.initials,
                  className:
                      'size-5 shrink-0 overflow-hidden '
                      'rounded-full bg-surface-container-high',
                  textClassName: 'text-[10px] font-semibold text-fg',
                ),
                onTap: () => controller.addOverride(member),
              ),
          ],
          child: MSButton(
            intent: ButtonIntent.secondary,
            child: WText(trans('uptizm.teams.oncall_override_button')),
          ),
        );
      },
    );
  }

  // ---------------------------------------------------------------------------
  // Hero card
  // ---------------------------------------------------------------------------

  /// Builds the current-responder hero card from `GET /on-call/current`.
  ///
  /// Two shapes, no third: a resolved responder (name, why they hold the pager,
  /// their team role when the roster knows it) or the honest "no one is on
  /// call" state in the neutral [StatusKey.paused] tone.
  Widget _buildHeroCard() {
    final OnCallResponder? responder = controller.currentResponder;
    if (responder == null) return _buildNobodyOnCallCard();

    final OnCallOverrideWindow? override = controller.activeOverride;
    final OnCallRotationSlot? slot = controller.slotFor(responder.id);
    // Why this person holds the pager: an override window that covers now, the
    // shift length of their ring slot, or nothing we can state (the server
    // resolved them through a ring slot this payload does not carry).
    final String? reason = override != null
        ? trans('uptizm.teams.oncall_override_until', {
            'until': formatMonthDayTime(override.endsAt),
          })
        : (slot != null ? _shiftLabel(slot) : null);

    return MSCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          _buildAvatarTile(
            responder.initials,
            className:
                'size-14 shrink-0 overflow-hidden '
                'rounded-full bg-primary',
            textClassName: 'text-base font-semibold text-on-primary',
          ),
          const SizedBox(width: 16),
          Expanded(
            child: WDiv(
              className: 'flex flex-col min-w-0',
              children: [
                WDiv(
                  className: 'flex flex-row items-center gap-2',
                  children: [
                    const StatusDot(StatusKey.up),
                    WText(
                      trans('uptizm.teams.oncall_current_header'),
                      className:
                          'text-xs font-medium uppercase '
                          'tracking-wide text-up-soft-foreground',
                    ),
                  ],
                ),
                WText(
                  responder.name,
                  className: 'mt-1 truncate text-lg font-semibold text-fg',
                ),
                if (reason != null)
                  WText(
                    reason,
                    className:
                        'truncate font-mono text-xs tabular-nums text-fg-muted',
                  ),
              ],
            ),
          ),
          _buildResponderRoleBadge(responder),
        ],
      ),
    );
  }

  /// Builds the honest "nobody holds the pager" hero card.
  ///
  /// Reached when the backend resolves `user: null`: an empty ring with no
  /// covering override. The neutral tone and the em-dash avatar follow the
  /// project's no-data convention (see the monitor detail's null reliability
  /// state) rather than falling back to any placeholder person.
  Widget _buildNobodyOnCallCard() {
    return MSCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          _buildAvatarTile(
            '—',
            className:
                'size-14 shrink-0 overflow-hidden '
                'rounded-full bg-surface-container-high',
            textClassName: 'text-base font-semibold text-fg-muted',
          ),
          const SizedBox(width: 16),
          Expanded(
            child: WDiv(
              className: 'flex flex-col min-w-0',
              children: [
                WDiv(
                  className: 'flex flex-row items-center gap-2',
                  children: [
                    const StatusDot(StatusKey.paused),
                    WText(
                      trans('uptizm.teams.oncall_current_header'),
                      className:
                          'text-xs font-medium uppercase '
                          'tracking-wide text-paused-soft-foreground',
                    ),
                  ],
                ),
                WText(
                  trans('uptizm.teams.oncall_nobody_title'),
                  className: 'mt-1 text-lg font-semibold text-fg',
                ),
                WText(
                  trans('uptizm.teams.oncall_nobody_description'),
                  className: 'text-xs text-fg-muted',
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// The resolved responder's team-role badge, or nothing when the real roster
  /// does not (yet) say what their role is.
  ///
  /// A role badge is an access-level claim and the responder payload
  /// (`id`/`name`/`email`) carries none, so the role is looked up in the real
  /// roster and simply omitted when it is not there. Listens to the roster so
  /// the badge appears as soon as it lands.
  Widget _buildResponderRoleBadge(OnCallResponder responder) {
    return ValueListenableBuilder<List<Map<String, dynamic>>>(
      valueListenable: _team.members,
      builder: (context, members, _) {
        for (final TeamResponder candidate in _respondersFrom(members)) {
          if (candidate.id != responder.id || candidate.role == null) continue;
          return MSBadge(candidate.role!.label, tone: BadgeTone.outline);
        }
        return const SizedBox.shrink();
      },
    );
  }

  // ---------------------------------------------------------------------------
  // Rotation section
  // ---------------------------------------------------------------------------

  /// Builds the "Rotation" heading, the ring (or its empty state), the
  /// add-to-rotation control, and the schedule's own name/timezone note.
  Widget _buildRotationSection() {
    final List<OnCallRotationSlot> ring = controller.rotation;

    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(
          trans('uptizm.teams.oncall_rotation_header'),
          className:
              'px-1 text-xs font-medium uppercase tracking-wide text-fg-muted',
        ),
        if (ring.isEmpty)
          _buildRotationEmptyState()
        else
          MSCard(
            noPadding: true,
            child: WDiv(
              className: 'flex flex-col',
              children: [
                for (final (int index, OnCallRotationSlot slot) in ring.indexed)
                  _buildRotationRow(slot, index: index, total: ring.length),
              ],
            ),
          ),
        // A rotation must keep at least one responder: the last row's remove
        // control is disabled to enforce it, and this near-field note makes the
        // invariant visible before the user reaches for a round trip they
        // cannot make (mirrors the escalation editor's "at least one target").
        if (ring.length == 1)
          WText(
            trans('uptizm.teams.oncall_min_responder_hint'),
            className: 'px-1 text-xs text-fg-muted',
          ),
        _buildAddOrUpgrade(),
        ..._buildScheduleMeta(),
      ],
    );
  }

  /// Builds the "the ring is empty" state: the schedule exists but has no
  /// responder, so nobody is paged. Stated plainly, with the add control right
  /// below it.
  Widget _buildRotationEmptyState() {
    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: MSEmptyState(
        icon: Icons.person_add_alt_outlined,
        title: trans('uptizm.teams.oncall_rotation_empty_title'),
        description: trans('uptizm.teams.oncall_rotation_empty_description'),
      ),
    );
  }

  /// Builds one rotation row: initials tile, the server's responder name and
  /// shift length, a "on call now" badge for the resolved responder, and the
  /// reorder / remove controls.
  Widget _buildRotationRow(
    OnCallRotationSlot slot, {
    required int index,
    required int total,
  }) {
    final bool isLast = index == total - 1;
    final bool isCurrent = controller.currentResponder?.id == slot.userId;

    return WDiv(
      className: isLast
          ? 'flex flex-row items-center gap-3 px-5 py-3.5'
          : 'flex flex-row items-center gap-3 px-5 py-3.5 border-b border-color-border',
      children: [
        _buildAvatarTile(
          slot.initials,
          className:
              'size-9 shrink-0 overflow-hidden '
              'rounded-full bg-surface-container-high',
          textClassName: 'text-xs font-semibold text-fg',
        ),
        Expanded(
          child: WDiv(
            className: 'flex flex-col min-w-0',
            children: [
              WText(
                slot.userName ?? '—',
                className: 'truncate text-sm font-medium text-fg',
              ),
              WText(
                _shiftLabel(slot),
                className:
                    'truncate font-mono text-xs tabular-nums text-fg-muted',
              ),
            ],
          ),
        ),
        if (isCurrent)
          MSBadge(
            trans('uptizm.teams.oncall_current_header'),
            tone: BadgeTone.primary,
          ),
        WDiv(
          className: 'flex flex-row shrink-0 items-center gap-1',
          children: [
            if (total > 1) ...[
              _buildIconButton(
                icon: _moveUpIcon,
                label: trans('uptizm.teams.oncall_move_up_button'),
                onPressed: index == 0 ? null : () => _move(index, index - 1),
              ),
              _buildIconButton(
                icon: _moveDownIcon,
                label: trans('uptizm.teams.oncall_move_down_button'),
                onPressed: isLast ? null : () => _move(index, index + 1),
              ),
            ],
            _buildIconButton(
              icon: _removeIcon,
              label: trans('uptizm.teams.oncall_remove_button'),
              onPressed: total == 1 ? null : () => _confirmRemove(slot),
            ),
          ],
        ),
      ],
    );
  }

  /// The shift-length label for [slot], e.g. `"24 h shift"`.
  ///
  /// The backend stores a shift LENGTH per slot, not a wall-clock span, so this
  /// never claims a `"Mon 09:00 - Wed 09:00"` window the server never sent.
  String _shiftLabel(OnCallRotationSlot slot) {
    return trans('uptizm.teams.oncall_shift_hours', {
      'hours': '${slot.shiftHours}',
    });
  }

  /// The schedule's own name and timezone, when it carries them.
  ///
  /// Replaces the old hardcoded "Weekly handoff, Mondays at 09:00" cadence
  /// note, which was a fixture string unrelated to the real schedule.
  List<Widget> _buildScheduleMeta() {
    final OnCallSchedule? schedule = controller.schedule;
    final String name = schedule?.name ?? '';
    final String timezone = schedule?.timezone ?? '';
    if (name.isEmpty || timezone.isEmpty) return const [];

    return [
      WText(
        trans('uptizm.teams.oncall_schedule_meta', {
          'name': name,
          'timezone': timezone,
        }),
        className: 'px-1 text-xs text-fg-muted',
      ),
    ];
  }

  /// Moves the slot at [from] to [to] and persists the new ring order.
  Future<void> _move(int from, int to) async {
    final List<OnCallRotationSlot> reordered = [...controller.rotation];
    reordered.insert(to, reordered.removeAt(from));

    await controller.reorderRotation(reordered);
  }

  // ---------------------------------------------------------------------------
  // Add-to-rotation control
  // ---------------------------------------------------------------------------

  /// Builds the add-to-rotation picker or, once the team's responder cap is
  /// reached, an [MSUpgradeNudge] naming the cheapest plan that lifts it.
  ///
  /// Wrapped in a [ListenableBuilder] on [EntitlementController] so the cap
  /// re-resolves the moment the real plan lands, mirroring the backend's own
  /// responder-cap 422 on invite.
  Widget _buildAddOrUpgrade() {
    return ListenableBuilder(
      listenable: EntitlementController.instance,
      builder: (context, _) => _buildAddOrUpgradeBody(),
    );
  }

  Widget _buildAddOrUpgradeBody() {
    final int? limit = EntitlementController.instance.currentLimits.responders;
    final bool atLimit = limit != null && controller.rotation.length >= limit;

    if (atLimit) {
      final int cappedLimit = limit;
      bool liftsResponderCap(PlanLimits l) =>
          l.responders == null || l.responders! > cappedLimit;
      final EntitlementController entitlement = EntitlementController.instance;

      return MSUpgradeNudge(
        message: trans('uptizm.teams.oncall_add_button'),
        requiredPlan: entitlement.planNameUnlocking(liftsResponderCap),
        onUpgrade: () => UpgradePrompt.startUpgrade(
          entitlement.planIdUnlocking(liftsResponderCap),
        ),
      );
    }

    return ValueListenableBuilder<List<Map<String, dynamic>>>(
      valueListenable: _team.members,
      builder: (context, members, _) => _buildMemberPicker(members),
    );
  }

  /// Builds the picker of real team members not already in the ring. Renders
  /// nothing when every member is already a responder (or the roster has not
  /// landed): an empty picker would only be able to offer nobody.
  Widget _buildMemberPicker(List<Map<String, dynamic>> members) {
    final List<TeamResponder> available = [
      for (final TeamResponder member in _respondersFrom(members))
        if (controller.slotFor(member.id) == null) member,
    ];
    if (available.isEmpty) return const SizedBox.shrink();

    return MSDropdownMenu(
      items: [
        for (final TeamResponder member in available)
          MSDropdownMenuItem(
            label: member.name,
            leading: _buildAvatarTile(
              member.initials,
              className:
                  'size-5 shrink-0 overflow-hidden '
                  'rounded-full bg-surface-container-high',
              textClassName: 'text-[10px] font-semibold text-fg',
            ),
            onTap: () => controller.addToRotation(member),
          ),
      ],
      child: WDiv(
        className: 'self-start',
        child: MSButton(
          intent: ButtonIntent.ghost,
          size: ButtonSize.sm,
          child: WText(trans('uptizm.teams.oncall_add_button')),
        ),
      ),
    );
  }

  /// Decodes the starter's raw member roster into [TeamResponder]s, dropping
  /// any row without a usable id or name (nothing on this screen may render a
  /// person the roster could not identify).
  List<TeamResponder> _respondersFrom(List<Map<String, dynamic>> members) =>
      TeamResponder.listFromMemberMaps(members);

  // ---------------------------------------------------------------------------
  // Overrides section
  // ---------------------------------------------------------------------------

  /// Builds the "Overrides" heading and one row per live override window.
  ///
  /// Only reached when the schedule actually carries overrides, so this section
  /// never invents a swap. The override covering right now is badged as active,
  /// which is also the explanation for the hero card's responder.
  Widget _buildOverridesSection() {
    final List<OnCallOverrideWindow> windows = controller.overrides;
    final OnCallOverrideWindow? active = controller.activeOverride;

    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(
          trans('uptizm.teams.oncall_overrides_header'),
          className:
              'px-1 text-xs font-medium uppercase tracking-wide text-fg-muted',
        ),
        MSCard(
          noPadding: true,
          child: WDiv(
            className: 'flex flex-col',
            children: [
              for (final (int index, OnCallOverrideWindow window)
                  in windows.indexed)
                _buildOverrideRow(
                  window,
                  isActive: window.id == active?.id,
                  isLast: index == windows.length - 1,
                ),
            ],
          ),
        ),
      ],
    );
  }

  /// Builds one override row: the covering responder, the window, an active
  /// badge when it covers now, and the lift control.
  Widget _buildOverrideRow(
    OnCallOverrideWindow window, {
    required bool isActive,
    required bool isLast,
  }) {
    return WDiv(
      className: isLast
          ? 'flex flex-row items-center gap-3 px-5 py-3.5'
          : 'flex flex-row items-center gap-3 px-5 py-3.5 border-b border-color-border',
      children: [
        _buildAvatarTile(
          window.initials,
          className:
              'size-9 shrink-0 overflow-hidden '
              'rounded-full bg-surface-container-high',
          textClassName: 'text-xs font-semibold text-fg',
        ),
        Expanded(
          child: WDiv(
            className: 'flex flex-col min-w-0',
            children: [
              WText(
                window.userName ?? '—',
                className: 'truncate text-sm font-medium text-fg',
              ),
              WText(
                trans('uptizm.teams.oncall_override_window', {
                  'start': formatMonthDayTime(window.startsAt),
                  'end': formatMonthDayTime(window.endsAt),
                }),
                className:
                    'truncate font-mono text-xs tabular-nums text-fg-muted',
              ),
            ],
          ),
        ),
        if (isActive)
          MSBadge(
            trans('uptizm.teams.oncall_override_active_badge'),
            tone: BadgeTone.primary,
          ),
        MSButton(
          intent: ButtonIntent.ghost,
          size: ButtonSize.sm,
          onPressed: () => _confirmLiftOverride(window),
          child: WText(trans('uptizm.teams.oncall_override_remove_button')),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Shared leaves
  // ---------------------------------------------------------------------------

  /// Builds an initials avatar tile.
  ///
  /// The centring is a Flutter [Center] rather than a Wind class, and that is
  /// deliberate on both halves. These tiles used to say
  /// `grid ... place-items-center`: Wind implements `grid` and does NOT
  /// implement `place-items-*`, and an unknown token there is a silent no-op, so
  /// every avatar in this view painted its initials against the TOP-LEFT edge of
  /// the circle, clipped by it. Measured at 1200px on a 56px hero circle.
  ///
  /// `flex items-center justify-center` is the supported spelling and centres
  /// correctly, but a single-child Wind flex box hands its child an unbounded
  /// main axis and does not shrink it even under `overflow-hidden` (the
  /// Flexible wrap lives in the multi-child branch of `w_div.dart` only). Two
  /// initials in a 20px circle then overflow the row by half a pixel. [Center]
  /// centres both axes with no Row in the way, so neither trap applies.
  Widget _buildAvatarTile(
    String initials, {
    required String className,
    required String textClassName,
  }) {
    return WDiv(
      className: className,
      child: Center(child: WText(initials, className: textClassName)),
    );
  }

  /// Builds one compact icon-only row control. A `null` [onPressed] renders it
  /// disabled, and [label] is its accessible name (the glyph carries no text).
  Widget _buildIconButton({
    required IconData icon,
    required String label,
    required VoidCallback? onPressed,
  }) {
    return MSButton(
      intent: ButtonIntent.ghost,
      size: ButtonSize.sm,
      disabled: onPressed == null,
      onPressed: onPressed,
      semanticLabel: label,
      child: WIcon(icon, className: 'text-sm'),
    );
  }

  // ---------------------------------------------------------------------------
  // Destructive confirmations
  // ---------------------------------------------------------------------------

  /// Opens the remove-responder confirm dialog; on confirm, removes [slot]
  /// through the controller (which re-reads the API afterwards).
  ///
  /// Mirrors `team_members_view.dart`'s `_confirmRemove`, including the
  /// `if (!mounted) return;` guard after the awaited dialog.
  Future<void> _confirmRemove(OnCallRotationSlot slot) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.teams.oncall_remove_confirm_title', {
        'name': slot.userName ?? '',
      }),
      description: trans('uptizm.teams.oncall_remove_confirm_description'),
      confirmLabel: trans('uptizm.teams.oncall_remove_confirm_label'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    // Guard against the async dialog gap: the view may have been popped while
    // the confirm dialog was open (mirrors team_members_view's precedent).
    if (!mounted) return;

    await controller.removeFromRotation(slot);
  }

  /// Opens the lift-override confirm dialog; on confirm, lifts [window]
  /// through the controller. Lifting hands the pager back to whoever the ring
  /// resolves to, which is why it is confirmed rather than one-tap.
  Future<void> _confirmLiftOverride(OnCallOverrideWindow window) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.teams.oncall_override_remove_confirm_title', {
        'name': window.userName ?? '',
      }),
      description: trans(
        'uptizm.teams.oncall_override_remove_confirm_description',
      ),
      confirmLabel: trans('uptizm.teams.oncall_override_remove_confirm_label'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    if (!mounted) return;

    await controller.removeOverride(window);
  }
}
