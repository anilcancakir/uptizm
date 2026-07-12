import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/escalation_controller.dart';
import '../../../app/mocks/oncall.dart';
import '../../../app/mocks/status.dart';
import '../../../app/mocks/teams_data.dart' show escalationDelayLabel;
import '../../../ui/components/status_dot/index.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Escalation Policies list screen (`/teams/escalation`).**
///
/// A Flutter port of the React `EscalationPoliciesPage.tsx`: a page header
/// with a "New policy" action and one [Card] per [EscalationPolicy], each
/// rendering its ladder of [EscalationStep] rungs as a vertical timeline
/// (a [StatusDot] + connecting line, the uppercase [escalationDelayLabel],
/// and the rung's targets as small token-tinted pills), plus a "Repeats last
/// rung" footer when [EscalationPolicy.repeatLastStep] is set.
///
/// Sources [EscalationController.policies] (live `GET /escalation-policies`
/// + per-policy detail hydration) instead of the design-lab fixtures; see
/// the controller's class docblock for the wire-shape divergence
/// (`description`/`repeatLastStep`/`isDefault`/`monitorCount` default since
/// the backend model does not persist them yet).
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
    extends MagicStatefulViewState<EscalationController, EscalationPoliciesView> {
  /// The repeat-last-rung footer glyph, matching the React source's loop icon.
  static const IconData _repeatIcon = Icons.repeat;

  @override
  void initState() {
    // Register the controller before the base state resolves it via
    // Magic.find<T>() (which throws when unregistered). Idempotent.
    Magic.findOrPut(EscalationController.new);
    super.initState();
  }

  @override
  Widget build(BuildContext context) {
    final List<EscalationPolicy> policies = controller.policies;
    // A plain Flutter Column scaffolds the page body so each descendant gets
    // a proper bounded width from PageContainer (same discipline as
    // StatusPagesListView / OnCallScheduleView).
    return PageContainer(
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
  // Policy card
  // ---------------------------------------------------------------------------

  /// Builds one policy [Card]: header (name, Default badge, monitor count,
  /// Edit/Delete actions), the escalation ladder, and the repeat-last-rung
  /// footer. Mirrors the React `PolicyCard`.
  Widget _buildPolicyCard(EscalationPolicy policy) {
    return MSCard(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          _buildPolicyHeader(policy),
          _buildLadder(policy),
          if (policy.repeatLastStep) _buildRepeatFooter(),
        ],
      ),
    );
  }

  /// Builds the card header: name + optional Default badge + monitor-count
  /// caption on the left, Edit + (non-default) Delete on the right.
  Widget _buildPolicyHeader(EscalationPolicy policy) {
    return WDiv(
      className: 'flex flex-row items-start justify-between gap-3',
      children: [
        WDiv(
          className: 'flex flex-col min-w-0 flex-1',
          children: [
            WDiv(
              className: 'flex flex-row flex-wrap items-center gap-2',
              children: [
                WText(policy.name, className: 'text-sm font-semibold text-fg'),
                if (policy.isDefault) _buildDefaultBadge(),
                WText(
                  '· ${policy.monitorCount} ${_monitorCountLabel(policy.monitorCount)}',
                  className: 'font-mono text-xs tabular-nums text-fg-muted',
                ),
              ],
            ),
            WText(policy.description, className: 'mt-1 text-sm text-fg-muted'),
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
            if (!policy.isDefault)
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

  /// Resolves the monitor-count word ("monitor" singular, "monitors" plural).
  String _monitorCountLabel(int count) {
    return count == 1
        ? trans('uptizm.teams.escalation_policy_count_word_singular')
        : trans('uptizm.teams.escalation_policy_count_word_plural');
  }

  /// Builds the "Default" badge: a small token-tinted pill mirroring the
  /// React `Badge tone="primary"` (same shape as `TeamMembersView`'s owner
  /// role pill, NOT [StatusBadge] since "Default" is not a [StatusKey]).
  Widget _buildDefaultBadge() {
    return WDiv(
      className:
          'flex flex-row items-center rounded-full bg-primary-container px-2.5 py-0.5',
      child: WText(
        trans('uptizm.teams.escalation_policy_default_badge'),
        className: 'text-xs font-medium text-fg',
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Escalation ladder
  // ---------------------------------------------------------------------------

  /// Builds the vertical step ladder: one row per [EscalationStep], each a
  /// [StatusDot] + connecting line, the uppercase delay label, and the
  /// rung's targets as pills.
  Widget _buildLadder(EscalationPolicy policy) {
    return WDiv(
      className: 'flex flex-col',
      children: [
        for (int i = 0; i < policy.steps.length; i++)
          _buildRung(policy.steps[i], isLast: i == policy.steps.length - 1),
      ],
    );
  }

  /// Builds one rung row: a leading dot, followed by the uppercase delay label
  /// and the rung's target pills.
  ///
  /// The connecting line is an explicit [Positioned] bar painted behind the
  /// row (not an `Expanded` inside a Column), mirroring `incident_timeline`'s
  /// rail: `Expanded` needs a bounded main-axis the page scroll view cannot
  /// give, and `IntrinsicHeight` cannot measure through the target pills'
  /// `flex-wrap` LayoutBuilder. A Positioned bar sidesteps both.
  Widget _buildRung(EscalationStep step, {required bool isLast}) {
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
                    escalationDelayLabel(step.afterMinutes).toUpperCase(),
                    className: 'text-xs font-medium text-fg-muted',
                  ),
                  WDiv(
                    className: 'mt-1.5 flex flex-row flex-wrap gap-1.5',
                    children: [
                      for (final String target in step.targets)
                        _buildTargetPill(target),
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

  /// Builds the "Repeats last rung" footer shown when the policy's last rung
  /// keeps firing until acknowledgement.
  Widget _buildRepeatFooter() {
    return WDiv(
      className:
          'flex flex-row items-center gap-1.5 border-t border-color-border pt-4',
      children: [
        WIcon(_repeatIcon, className: 'text-sm text-fg-muted'),
        WText(
          trans('uptizm.teams.escalation_policy_repeats_last'),
          className: 'text-xs text-fg-muted',
        ),
      ],
    );
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
        'name': policy.name,
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
