import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import '../../../app/mocks/oncall.dart';
import '../../../app/mocks/status.dart';
import '../../../app/mocks/teams_data.dart';
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
/// Delete opens a [MagicStarterConfirmDialog]; on confirm it removes the
/// policy from the local working copy and surfaces a [Magic.success] toast,
/// with the `if (!mounted) return;` guard after the awaited dialog (mirrors
/// `sessions_settings_view.dart`'s `_confirmSignOut` exactly). This is a mock
/// screen: nothing persists past the local widget state.
///
/// ### Example
/// ```dart
/// // Registered as the routed `/teams/escalation` content (Step 11):
/// MagicRoute.page('/teams/escalation', () => const EscalationPoliciesView());
/// ```
@immutable
class EscalationPoliciesView extends StatefulWidget {
  /// Creates the [EscalationPoliciesView].
  const EscalationPoliciesView({super.key});

  @override
  State<EscalationPoliciesView> createState() => _EscalationPoliciesViewState();
}

class _EscalationPoliciesViewState extends State<EscalationPoliciesView> {
  /// The repeat-last-rung footer glyph, matching the React source's loop icon.
  static const IconData _repeatIcon = Icons.repeat;

  /// The mutable working copy of the team's escalation policies.
  ///
  /// Seeded once in [initState] from [escalationPolicies]; the fixture list
  /// is never mutated in place. Delete mutates this list via [setState].
  late List<EscalationPolicy> _policies;

  @override
  void initState() {
    super.initState();
    _policies = escalationPolicies.toList();
  }

  @override
  Widget build(BuildContext context) {
    // A plain Flutter Column scaffolds the page body so each descendant gets
    // a proper bounded width from PageContainer (same discipline as
    // StatusPagesListView / OnCallScheduleView).
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          PageHeader(
            title: trans('uptizm.teams.escalation_title'),
            subtitle: trans('uptizm.teams.escalation_description'),
            backLabel: trans('uptizm.status.editor_breadcrumb_back'),
            backFallback: '/',
            actions: [
              Button(
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
              for (final EscalationPolicy policy in _policies)
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
    return Card(
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
            Button(
              intent: ButtonIntent.secondary,
              size: ButtonSize.sm,
              onPressed: () => MagicRoute.to('/teams/escalation/${policy.id}'),
              child: WText(trans('uptizm.teams.escalation_policy_edit_button')),
            ),
            if (!policy.isDefault)
              Button(
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

  /// Builds one rung row: a leading dot-and-line column, followed by the
  /// uppercase delay label and the rung's target pills.
  Widget _buildRung(EscalationStep step, {required bool isLast}) {
    return WDiv(
      className: 'flex flex-row gap-3',
      children: [
        _buildRungRail(isLast: isLast),
        WDiv(
          className: isLast
              ? 'flex flex-col min-w-0 flex-1'
              : 'flex flex-col min-w-0 flex-1 pb-5',
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
    );
  }

  /// Builds the leading rail: a [StatusDot] followed by a connecting line for
  /// every rung except the last.
  Widget _buildRungRail({required bool isLast}) {
    return SizedBox(
      width: 16,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          const SizedBox(height: 4),
          const StatusDot(StatusKey.up, size: StatusDotSize.md),
          if (!isLast)
            Expanded(
              child: WDiv(className: 'w-px border-r border-color-border'),
            ),
        ],
      ),
    );
  }

  /// Builds a small token-tinted target pill: a rounded [WDiv] + [WText]
  /// carrying [target]'s label. NOT [StatusBadge] — targets are plain
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

  /// Opens the delete [MagicStarterConfirmDialog]; on confirm, removes
  /// [policy] from the local list and surfaces a success toast. Mirrors
  /// `sessions_settings_view.dart`'s `_confirmSignOut` exactly.
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

    setState(() => _policies.remove(policy));
    Magic.success(
      trans('uptizm.teams.escalation_policy_delete_confirm_label'),
      policy.name,
    );
  }
}
