import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/refetches_on_mount.dart';
import '../../../app/controllers/escalation_controller.dart';
import '../../../app/models/escalation_policy.dart';
import '../../../app/enums/status_key.dart';
import '../../../app/support/escalation_support.dart' show escalationDelayLabel;
import '../../../ui/components/status_dot/index.dart';

/// **The Escalation Policies list screen (`/teams/escalation`).**
///
/// A Flutter port of the React `EscalationPoliciesPage.tsx`: a page header
/// with a "New policy" action and one [Card] per [EscalationPolicy], each
/// rendering its ladder of [EscalationStepWire] rungs as a vertical timeline
/// (a [StatusDot] + connecting line, the uppercase [escalationDelayLabel],
/// and the rung's target as a small token-tinted pill).
///
/// Sources [EscalationController.policies] (live `GET /escalation-policies`
/// + per-policy detail hydration) as [EscalationPolicy] models. The backend
/// model persists only `name` + the step chain, so the card renders the policy
/// name plus its ladder and nothing else (there are no
/// `description`/`repeat_last_step`/`is_default`/`monitor_count` columns; see
/// the controller's class docblock for the divergence).
///
/// Delete opens a [MagicStarterConfirmDialog]; on confirm it fires
/// [EscalationController.delete] (`DELETE /escalation-policies/{id}`), which
/// reloads the roster and surfaces a toast, with the `if (!mounted) return;`
/// guard after the awaited dialog (mirrors `sessions_settings_view.dart`'s
/// `_confirmSignOut` exactly).
///
/// ### Example
/// ```dart
/// // Registered as the routed `/teams/escalation` content (Step 11):
/// MagicRoute.page('/teams/escalation', () => const EscalationPoliciesView());
/// ```
@immutable
class EscalationPoliciesView extends MagicStatefulView<EscalationController> {
  /// Creates the [EscalationPoliciesView].
  const EscalationPoliciesView({super.key});

  @override
  State<EscalationPoliciesView> createState() => _EscalationPoliciesViewState();
}

class _EscalationPoliciesViewState
    extends MagicStatefulViewState<EscalationController, EscalationPoliciesView>
    with RefetchesOnMount<EscalationController, EscalationPoliciesView> {
  @override
  void initState() {
    // Register the controller before the base state resolves it via
    // Magic.find<T>() (which throws when unregistered). Idempotent.
    Magic.findOrPut(EscalationController.new);
    super.initState();
  }

  /// Refetch on every mount: the backing controller loads in `onInit`, which
  /// magic fires only once per controller instance, so re-entering this route
  /// would otherwise re-render the data fetched the first time it was ever
  /// opened. See [RefetchesOnMount].
  @override
  Future<void> refetch() => controller.reload();

  @override
  Widget build(BuildContext context) {
    final List<EscalationPolicy> policies = controller.policies;
    // A plain Flutter Column scaffolds the page body so each descendant gets
    // a proper bounded width from MSPageContainer (same discipline as
    // StatusPagesListView / OnCallScheduleView).
    return MSPageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          MSPageHeader(
            title: trans('uptizm.teams.escalation_title'),
            subtitle: trans('uptizm.teams.escalation_description'),
            backLabel: trans('uptizm.status.editor_breadcrumb_back'),
            backFallback: '/',
            actions: [
              MSButton(
                intent: ButtonIntent.secondary,
                onPressed: () => MagicRoute.to('/teams/escalation/new'),
                child: WText(trans('uptizm.teams.escalation_new_button')),
              ),
            ],
          ),
          const SizedBox(height: 24),
          // Loading is not emptiness. Without the skeleton branch a team with a
          // configured ladder opened this screen on a bare page with no policy
          // cards at all and only grew them when the fetch landed, which reads
          // as "you have no escalation policy" for as long as the round trip
          // takes (the roster needs a per-policy detail hydration on top of the
          // index call, so that window is two round trips wide here).
          if (controller.isFirstLoad)
            _buildSkeleton()
          else
            WDiv(
              className: 'flex flex-col gap-4',
              children: [
                for (final EscalationPolicy policy in policies)
                  _buildPolicyCard(policy),
              ],
            ),
          const SizedBox(height: 24),
          WText(
            trans('uptizm.teams.escalation_oncall_reference'),
            className: 'text-sm text-fg-muted',
          ),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // First-load skeleton
  // ---------------------------------------------------------------------------

  /// Builds the first-load placeholder: the policy list's own shape, in
  /// skeletons.
  ///
  /// Same `gap-4` column rhythm as the real list, two cards deep: enough to read
  /// as a list without implying a specific policy count.
  Widget _buildSkeleton() {
    return WDiv(
      className: 'flex flex-col gap-4',
      children: [for (int i = 0; i < 2; i++) _buildSkeletonCard()],
    );
  }

  /// One skeleton card, matching [_buildPolicyCard]'s frame and internal
  /// rhythm: the same [MSCard] shell and `gap-4` column around a header row
  /// (name + the two trailing actions) and a two-rung ladder.
  ///
  /// Every text placeholder carries an explicit height, matching the line box of
  /// the text it stands in for (20px for `text-sm`, 16px for `text-xs`). Without
  /// one an [MSSkeleton] collapses: its `WDiv` has no child to measure, so in a
  /// flex column it lays out 0px tall and the placeholder is invisible.
  Widget _buildSkeletonCard() {
    return MSCard(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          WDiv(
            className: 'flex flex-row items-start justify-between gap-3',
            children: const [
              MSSkeleton(shape: SkeletonShape.text, width: 140, height: 20),
              WDiv(
                className: 'flex flex-row shrink-0 items-center gap-2',
                children: [
                  MSSkeleton(width: 64, height: 32),
                  MSSkeleton(width: 72, height: 32),
                ],
              ),
            ],
          ),
          WDiv(
            className: 'flex flex-col',
            children: [
              for (int i = 0; i < 2; i++) _buildSkeletonRung(isLast: i == 1),
            ],
          ),
        ],
      ),
    );
  }

  /// One skeleton rung, matching [_buildRung]'s geometry: the 16px rail slot
  /// holding the leading dot, the uppercase delay label line, and the target
  /// pill, with the same 20px bottom gap on every rung but the last.
  ///
  /// The rail's connecting line is deliberately absent: it is a [Positioned]
  /// bar sized to a real rung's height, and a skeleton has no measured rung to
  /// thread it between.
  Widget _buildSkeletonRung({required bool isLast}) {
    return Padding(
      padding: EdgeInsets.only(bottom: isLast ? 0 : 20),
      child: WDiv(
        className: 'flex flex-row gap-3',
        children: const [
          SizedBox(
            width: 16,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.center,
              mainAxisSize: MainAxisSize.min,
              children: [
                SizedBox(height: 4),
                MSSkeleton(shape: SkeletonShape.circle, width: 10, height: 10),
              ],
            ),
          ),
          WDiv(
            className: 'flex flex-col min-w-0 flex-1 gap-1.5',
            children: [
              MSSkeleton(shape: SkeletonShape.text, width: 96, height: 16),
              MSSkeleton(width: 120, height: 22),
            ],
          ),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Policy card
  // ---------------------------------------------------------------------------

  /// Builds one policy [Card]: header (name + Edit/Delete actions) and the
  /// escalation ladder. Mirrors the React `PolicyCard`, minus the fields the
  /// backend model does not persist (see the class docblock).
  Widget _buildPolicyCard(EscalationPolicy policy) {
    return MSCard(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          _buildPolicyHeader(policy),
          _buildLadder(policy),
        ],
      ),
    );
  }

  /// Builds the card header: the policy name on the left, Edit + Delete on the
  /// right.
  Widget _buildPolicyHeader(EscalationPolicy policy) {
    return WDiv(
      className: 'flex flex-row items-start justify-between gap-3',
      children: [
        WDiv(
          className: 'flex flex-col min-w-0 flex-1',
          children: [
            WText(
              policy.name ?? '',
              className: 'text-sm font-semibold text-fg',
            ),
          ],
        ),
        WDiv(
          className: 'flex flex-row shrink-0 items-center gap-2',
          children: [
            MSButton(
              intent: ButtonIntent.secondary,
              size: ButtonSize.sm,
              onPressed: () => MagicRoute.to('/teams/escalation/${policy.id}'),
              child: WText(trans('uptizm.teams.escalation_policy_edit_button')),
            ),
            MSButton(
              intent: ButtonIntent.ghost,
              size: ButtonSize.sm,
              onPressed: () => _confirmDelete(policy),
              child: WText(
                trans('uptizm.teams.escalation_policy_delete_button'),
              ),
            ),
          ],
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Escalation ladder
  // ---------------------------------------------------------------------------

  /// Builds the vertical step ladder: one row per [EscalationStepWire], each a
  /// [StatusDot] + connecting line, the uppercase delay label, and the
  /// rung's target as a pill.
  Widget _buildLadder(EscalationPolicy policy) {
    final List<EscalationStepWire> steps = policy.steps;
    return WDiv(
      className: 'flex flex-col',
      children: [
        for (int i = 0; i < steps.length; i++)
          _buildRung(steps[i], isLast: i == steps.length - 1),
      ],
    );
  }

  /// Builds one rung row: a leading dot, followed by the uppercase delay label
  /// and the rung's target pill.
  ///
  /// The connecting line is an explicit [Positioned] bar painted behind the
  /// row (not an `Expanded` inside a Column), mirroring `incident_timeline`'s
  /// rail: `Expanded` needs a bounded main-axis the page scroll view cannot
  /// give, and `IntrinsicHeight` cannot measure through the target pills'
  /// `flex-wrap` LayoutBuilder. A Positioned bar sidesteps both.
  Widget _buildRung(EscalationStepWire step, {required bool isLast}) {
    return Stack(
      children: [
        // Rail: a 1px line from just below this dot to the bottom of the row,
        // threading the bottom gap to reach the next rung's dot.
        if (!isLast)
          const Positioned(
            left: 7,
            top: 18,
            bottom: 0,
            width: 1,
            child: WDiv(className: 'border-l border-color-border'),
          ),
        Padding(
          padding: EdgeInsets.only(bottom: isLast ? 0 : 20),
          child: WDiv(
            className: 'flex flex-row gap-3',
            children: [
              // Leading dot (fixed-width rail slot; the line is the Positioned
              // bar above, so no Expanded is needed here).
              const SizedBox(
                width: 16,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.center,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    SizedBox(height: 4),
                    StatusDot(StatusKey.up, size: StatusDotSize.md),
                  ],
                ),
              ),
              WDiv(
                className: 'flex flex-col min-w-0 flex-1',
                children: [
                  WText(
                    escalationDelayLabel(step.delayMinutes).toUpperCase(),
                    className: 'text-xs font-medium text-fg-muted',
                  ),
                  WDiv(
                    className: 'mt-1.5 flex flex-row flex-wrap gap-1.5',
                    children: [
                      _buildTargetPill(_targetLabel(step)),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }

  /// Builds a small token-tinted target pill: a rounded [WDiv] + [WText]
  /// carrying [target]'s label. NOT [StatusBadge]: targets are plain
  /// notification-target strings, not a [StatusKey]. Mirrors the React
  /// `Badge tone="outline"` and `TeamMembersView`'s non-owner role pill.
  Widget _buildTargetPill(String target) {
    return WDiv(
      className:
          'flex flex-row items-center rounded-full border border-color-border px-2.5 py-0.5',
      child: WText(target, className: 'text-xs font-medium text-fg-muted'),
    );
  }

  /// Renders a step's target as a single display label: the localized on-call
  /// label for an `on_call` step, or the localized "User :id" for a `user`
  /// step (mirrors the backend's people-only, single-target-per-step shape;
  /// see the controller's class docblock). The default arm is an unreachable
  /// safety net now that the wire only carries `on_call`/`user`.
  String _targetLabel(EscalationStepWire step) {
    switch (step.targetType) {
      case 'on_call':
        return trans('uptizm.teams.escalation_target_oncall');
      case 'user':
        return trans('uptizm.teams.escalation_target_user', {
          'id': step.targetId ?? '',
        }).trim();
      default:
        return '';
    }
  }

  // ---------------------------------------------------------------------------
  // Delete confirmation
  // ---------------------------------------------------------------------------

  /// Opens the delete [MagicStarterConfirmDialog]; on confirm, fires
  /// [EscalationController.delete] (`DELETE /escalation-policies/{id}`),
  /// which reloads the roster and surfaces its own toast. Mirrors
  /// `sessions_settings_view.dart`'s `_confirmSignOut` exactly, including the
  /// `if (!mounted) return;` guard after the awaited dialog.
  Future<void> _confirmDelete(EscalationPolicy policy) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.teams.escalation_policy_delete_confirm_title', {
        'name': policy.name ?? '',
      }),
      description: trans(
        'uptizm.teams.escalation_policy_delete_confirm_description',
      ),
      confirmLabel: trans(
        'uptizm.teams.escalation_policy_delete_confirm_label',
      ),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    // Guard against the async dialog gap: the view may have been popped while
    // the confirm dialog was open (mirrors sessions_settings_view's precedent).
    if (!mounted) return;

    await controller.delete(policy.id);
  }
}
