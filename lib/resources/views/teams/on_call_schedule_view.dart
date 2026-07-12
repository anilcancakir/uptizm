import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/on_call_controller.dart';
import '../../../app/mocks/billing.dart';
import '../../../app/mocks/status.dart';
import '../../../app/mocks/teams_data.dart';
import '../../../ui/components/status_dot/index.dart';
import '../../../ui/components/upgrade_nudge/index.dart';
import '../../../ui/layouts/page_container.dart';

/// **On-call schedule screen (`/teams/on-call`).**
///
/// A faithful Flutter port of the React `OnCallSchedulePage.tsx`: who is
/// holding the pager right now, the upcoming rotation, and the controls to
/// edit it (override the current responder, add a member to the rotation, or
/// remove one).
///
/// - **Hero card** — the current responder: a large initials avatar tile, a
///   [StatusDot] + "On call now" label, the responder's name, and their span
///   from the rotation (falls back to the override label when the current
///   responder was set by an override rather than a scheduled shift).
/// - **Rotation card** — one row per [OnCallShift]: an initials avatar tile,
///   name + span, and either a "Now" [Badge] (the current shift) or a ghost
///   "Remove" [Button] (disabled once only one shift remains).
/// - **Add-to-rotation control** — a [DropdownMenu] listing [teamMembers] not
///   already in the rotation, OR, once `currentLimits.responders` caps the
///   rotation at its limit, an [UpgradeNudge] naming the cheapest plan that
///   lifts the cap ([smallestPlanWhere]).
/// - The [onCallCadence] note sits under the rotation card, and a footer line
///   links to `/teams/escalation`.
///
/// Override, Add-to-rotation, and Remove are live writes against the S27
/// `api/v1/on-call/*` endpoints through [OnCallController]: the rendered
/// rotation/hero cards stay sourced from the [onCallRotation]/[teamMembers]
/// fixtures (see the controller's class docblock for why), but each action
/// awaits the controller's write, mutates the local list via [setState] only
/// on success, and stays on the current state (no mutation, no toast beyond
/// the controller's own error toast) on failure. Remove opens a
/// [MagicStarterConfirmDialog] first; on confirm it awaits the controller's
/// delete before mutating the local list, mirroring
/// `team_members_view.dart`'s `_confirmRemove` (including the
/// `if (!mounted) return;` guard after the awaited dialog).
///
/// ### Example
/// ```dart
/// MagicRoute.page('/teams/on-call', () => const OnCallScheduleView());
/// ```
@immutable
class OnCallScheduleView extends StatefulWidget {
  /// Creates the [OnCallScheduleView].
  const OnCallScheduleView({super.key});

  @override
  State<OnCallScheduleView> createState() => _OnCallScheduleViewState();
}

class _OnCallScheduleViewState extends State<OnCallScheduleView> {
  /// The mutable working copy of the team's on-call rotation.
  ///
  /// Seeded once in [initState] from [onCallRotation]; the fixture list is
  /// never mutated in place. Add/Remove mutate this list via [setState].
  late List<OnCallShift> _rotation;

  /// The [TeamMember.id] of whoever currently holds the pager.
  ///
  /// Seeded from the shift marked [OnCallShift.current]; an Override moves
  /// this id without touching [_rotation]'s spans, mirroring the React
  /// source's separate `currentId` state.
  late String _currentId;

  @override
  void initState() {
    super.initState();
    _rotation = onCallRotation.toList();
    _currentId = _rotation
        .firstWhere(
          (OnCallShift shift) => shift.current,
          orElse: () => _rotation.first,
        )
        .memberId;
    // Warms the controller's schedule/ring cache so `removeFromRotation`
    // resolves a backend rotation id later, without blocking this build.
    OnCallController.instance;
  }

  /// The rotation shift matching [_currentId], or `null` when the current
  /// responder was set by an override to someone outside the rotation list.
  OnCallShift? get _currentShift {
    for (final OnCallShift shift in _rotation) {
      if (shift.memberId == _currentId) return shift;
    }
    return null;
  }

  /// Members not already present in [_rotation], offered by the "Add to
  /// rotation" control.
  List<TeamMember> get _availableMembers {
    return [
      for (final TeamMember member in teamMembers)
        if (!_rotation.any((OnCallShift shift) => shift.memberId == member.id))
          member,
    ];
  }

  @override
  Widget build(BuildContext context) {
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          MSPageHeader(
            title: trans('uptizm.teams.oncall_title'),
            subtitle: trans('uptizm.teams.oncall_description'),
            backLabel: trans('uptizm.status.editor_breadcrumb_back'),
            backFallback: '/',
            actions: [_buildOverrideControl()],
          ),
          const SizedBox(height: 24),
          _buildHeroCard(),
          const SizedBox(height: 24),
          _buildRotationSection(),
          const SizedBox(height: 24),
          WText(
            trans('uptizm.teams.oncall_escalation_reference'),
            className: 'text-sm text-fg-muted',
          ),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Override control
  // ---------------------------------------------------------------------------

  /// Builds the "Override" [DropdownMenu] listing every [teamMembers] entry;
  /// the current responder carries a trailing checkmark. Tapping an entry
  /// hands the pager to that member.
  Widget _buildOverrideControl() {
    return MSDropdownMenu(
      items: [
        for (final TeamMember member in teamMembers)
          MSDropdownMenuItem(
            label: member.name,
            leading: WDiv(
              className:
                  'grid size-5 shrink-0 place-items-center '
                  'rounded-full bg-surface-container-high',
              child: WText(
                member.initials,
                className: 'text-[10px] font-semibold text-fg',
              ),
            ),
            onTap: () => _override(member),
          ),
      ],
      child: MSButton(
        intent: ButtonIntent.secondary,
        child: WText(trans('uptizm.teams.oncall_override_button')),
      ),
    );
  }

  /// Hands the pager to [member] via `POST /on-call/schedules/:id/overrides`
  /// ([OnCallController.addOverride]); on success, moves [_currentId] without
  /// touching [_rotation]'s membership or spans. Surfaces its own success or
  /// error toast (see the controller's docblock), so this stays silent on
  /// either outcome beyond the local mutation.
  Future<void> _override(TeamMember member) async {
    final bool ok = await OnCallController.instance.addOverride(member);
    if (!ok || !mounted) return;

    setState(() => _currentId = member.id);
  }

  // ---------------------------------------------------------------------------
  // Hero card
  // ---------------------------------------------------------------------------

  /// Builds the current-responder hero [Card]: a large initials avatar tile,
  /// [StatusDot] + "On call now" label, the responder's name, and their span
  /// (falling back to the override label when the current responder is not a
  /// scheduled shift).
  Widget _buildHeroCard() {
    final TeamMember current = teamMembers.firstWhere(
      (TeamMember member) => member.id == _currentId,
      orElse: () => teamMembers.first,
    );
    // Falls back to the override label (mirrors the React source's
    // `?? "Override"`) when the current responder was set by an override
    // rather than a scheduled rotation shift.
    final String span =
        _currentShift?.span ?? trans('uptizm.teams.oncall_override_button');

    return MSCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          WDiv(
            className:
                'grid size-14 shrink-0 place-items-center '
                'rounded-full bg-primary',
            child: WText(
              current.initials,
              className: 'text-base font-semibold text-on-primary',
            ),
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
                  current.name,
                  className: 'mt-1 truncate text-lg font-semibold text-fg',
                ),
                WText(
                  span,
                  className:
                      'truncate font-mono text-xs tabular-nums text-fg-muted',
                ),
              ],
            ),
          ),
          MSBadge(current.role.label, tone: BadgeTone.outline),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Rotation section
  // ---------------------------------------------------------------------------

  /// Builds the "Rotation" heading, the rotation [Card] of shift rows, and
  /// either the add-to-rotation control or an [UpgradeNudge] once the plan's
  /// responder cap is reached, followed by the [onCallCadence] note.
  Widget _buildRotationSection() {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(
          trans('uptizm.teams.oncall_rotation_header'),
          className:
              'px-1 text-xs font-medium uppercase tracking-wide text-fg-muted',
        ),
        MSCard(
          noPadding: true,
          child: WDiv(
            className: 'flex flex-col',
            children: [
              for (final (int index, OnCallShift shift) in _rotation.indexed)
                _buildRotationRow(shift, isLast: index == _rotation.length - 1),
            ],
          ),
        ),
        _buildAddOrUpgrade(),
        WText('$onCallCadence.', className: 'px-1 text-xs text-fg-muted'),
      ],
    );
  }

  /// Builds one rotation row: initials avatar tile, name + span, and either
  /// a "Now" [Badge] (the current shift) or a ghost "Remove" [Button]
  /// (disabled once only one shift remains).
  Widget _buildRotationRow(OnCallShift shift, {required bool isLast}) {
    final bool isCurrent = shift.memberId == _currentId;

    return WDiv(
      className: isLast
          ? 'flex flex-row items-center gap-3 px-5 py-3.5'
          : 'flex flex-row items-center gap-3 px-5 py-3.5 border-b border-color-border',
      children: [
        WDiv(
          className:
              'grid size-9 shrink-0 place-items-center '
              'rounded-full bg-surface-container-high',
          child: WText(
            shift.initials,
            className: 'text-xs font-semibold text-fg',
          ),
        ),
        Expanded(
          child: WDiv(
            className: 'flex flex-col min-w-0',
            children: [
              WText(
                shift.memberName,
                className: 'truncate text-sm font-medium text-fg',
              ),
              WText(
                shift.span,
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
          )
        else
          MSButton(
            intent: ButtonIntent.ghost,
            size: ButtonSize.sm,
            onPressed: _rotation.length == 1
                ? null
                : () => _confirmRemove(shift),
            child: WText(trans('uptizm.teams.oncall_remove_button')),
          ),
      ],
    );
  }

  /// Builds the add-to-rotation [DropdownMenu] (members not already in the
  /// rotation) or, once the plan's responder cap is reached, an
  /// [UpgradeNudge] naming the cheapest plan that lifts it. Renders nothing
  /// when there is no cap and no member left to add.
  Widget _buildAddOrUpgrade() {
    final int? limit = currentLimits.responders;
    final bool atLimit = limit != null && _rotation.length >= limit;

    if (atLimit) {
      final int cappedLimit = limit;
      final String requiredPlan = smallestPlanWhere(
        (PlanLimits l) => l.responders == null || l.responders! > cappedLimit,
      ).name;

      return UpgradeNudge(
        message: trans('uptizm.teams.oncall_add_button'),
        requiredPlan: requiredPlan,
      );
    }

    final List<TeamMember> available = _availableMembers;
    if (available.isEmpty) return const SizedBox.shrink();

    return MSDropdownMenu(
      items: [
        for (final TeamMember member in available)
          MSDropdownMenuItem(
            label: member.name,
            leading: WDiv(
              className:
                  'grid size-5 shrink-0 place-items-center '
                  'rounded-full bg-surface-container-high',
              child: WText(
                member.initials,
                className: 'text-[10px] font-semibold text-fg',
              ),
            ),
            onTap: () => _addToRotation(member),
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

  /// Adds [member] to the rotation via
  /// `POST /on-call/schedules/:id/rotations` ([OnCallController.addToRotation]);
  /// on success, appends an "Unscheduled" span to [_rotation]. "Unscheduled"
  /// is not localized: it mirrors the React source's plain literal (a
  /// placeholder span for a newly added, not yet scheduled, responder), the
  /// same convention as the mock's `escalationDelayLabel` composing plain
  /// English. Surfaces its own success or error toast (see the controller's
  /// docblock), so this stays silent on either outcome beyond the local
  /// mutation.
  Future<void> _addToRotation(TeamMember member) async {
    final bool ok = await OnCallController.instance.addToRotation(member);
    if (!ok || !mounted) return;

    final OnCallShift shift = OnCallShift(
      memberId: member.id,
      memberName: member.name,
      initials: member.initials,
      span: 'Unscheduled',
      current: false,
    );

    setState(() => _rotation = [..._rotation, shift]);
  }

  // ---------------------------------------------------------------------------
  // Remove confirmation
  // ---------------------------------------------------------------------------

  /// Opens the Remove [MagicStarterConfirmDialog]; on confirm, removes
  /// [shift] via `DELETE /on-call/schedules/:id/rotations/:rotationId`
  /// ([OnCallController.removeFromRotation]) and, on success, removes it from
  /// the local rotation. Mirrors `team_members_view.dart`'s `_confirmRemove`
  /// (including the `if (!mounted) return;` guard after the awaited dialog).
  /// Surfaces its own success or error toast (see the controller's
  /// docblock), so this stays silent on either outcome beyond the local
  /// mutation.
  Future<void> _confirmRemove(OnCallShift shift) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.teams.oncall_remove_confirm_title', {
        'name': shift.memberName,
      }),
      description: trans('uptizm.teams.oncall_remove_confirm_description'),
      confirmLabel: trans('uptizm.teams.oncall_remove_confirm_label'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    // Guard against the async dialog gap: the view may have been popped while
    // the confirm dialog was open (mirrors team_members_view's precedent).
    if (!mounted) return;

    final bool ok = await OnCallController.instance.removeFromRotation(shift);
    if (!ok || !mounted) return;

    setState(() => _rotation.remove(shift));
  }
}
