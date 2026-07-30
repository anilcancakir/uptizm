import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/refetches_on_mount.dart';
import '../../../app/controllers/entitlement_controller.dart';
import '../../../app/controllers/status_page_controller.dart';
import '../../../app/enums/status_key.dart' show StatusKey;
import '../../../app/support/status_page_support.dart'
    show pageUrl, worstStatus;
import '../../../app/support/status_page_types.dart' show PublicComponent;
import '../../../app/models/status_page.dart';
import '../../../ui/components/status_badge/index.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Status Pages list screen.**
///
/// Renders every configured public status page from [StatusPageController]: a
/// page header with a "New status page" action and a responsive card grid, one
/// card per [StatusPage]. Each card's component count and overall status badge
/// come from the page's own eager-loaded components, so a page whose monitors
/// are down never reads as operational.
/// An [MSEmptyState] placeholder is shown when the roster is empty.
///
/// Layout follows the same discipline as [IncidentsListView]: a plain Flutter
/// [Column] scaffolds the page body so leaf components receive a bounded
/// full-width constraint from the shared [PageContainer]; Wind utilities only
/// appear on leaf containers, never as the outermost flex-scroll context.
///
/// Composition mirrors `StatusPagesListPage.tsx`:
///   header → card grid or empty state.
///
/// ### Example
/// ```dart
/// // Registered as the routed `/status` content (wrapped by the app shell):
/// MagicStarter.view.makeLayout('layout.app', child: const StatusPagesListView())
/// ```
@immutable
class StatusPagesListView extends MagicStatefulView<StatusPageController> {
  /// Creates the [StatusPagesListView].
  const StatusPagesListView({super.key});

  @override
  State<StatusPagesListView> createState() => _StatusPagesListViewState();
}

class _StatusPagesListViewState
    extends MagicStatefulViewState<StatusPageController, StatusPagesListView>
    with RefetchesOnMount<StatusPageController, StatusPagesListView> {
  /// Shared billing entitlement driving the New-status-page cap. Listened to so
  /// the gate re-renders when the real plan and usage land from the backend.
  final EntitlementController _entitlement = EntitlementController.instance;

  @override
  void initState() {
    Magic.findOrPut(StatusPageController.new);
    super.initState();
    _entitlement.addListener(_onEntitlement);
  }

  @override
  void dispose() {
    _entitlement.removeListener(_onEntitlement);
    super.dispose();
  }

  /// Re-render the New-page gate when the real entitlement (plan + usage) lands.
  void _onEntitlement() {
    if (mounted) setState(() {});
  }

  /// Whether the team is below its plan's status-page cap. Uses the loaded list
  /// count (the freshest source) against the entitlement's limit; unlimited
  /// (null limit) is always allowed.
  bool get _canCreateStatusPage {
    final int? limit = _entitlement.currentLimits.statusPages;
    return limit == null || controller.statusPages.length < limit;
  }

  /// Nudges to upgrade when the New-page action is tapped at the cap, mirroring
  /// the backend's own 422 message so the two never diverge.
  void _nudgeStatusPageLimit() {
    final int? limit = _entitlement.currentLimits.statusPages;
    // Same resolution as the monitors cap: the refusal is known client-side, so
    // the entitling tier comes from the catalog rather than from a gated
    // response. A blank id lands on billing without a purchase intent.
    final int used = controller.statusPages.length;
    final String requiredPlan = _entitlement.planIdUnlocking(
      (limits) => limits.statusPages == null || limits.statusPages! > used,
    );

    UpgradePrompt.show(
      PlanUpgradeRequirement(
        message: trans('uptizm.status.limit_nudge', {
          'plan': _entitlement.planName,
          'count': '$limit',
          'noun': trans(
            limit == 1
                ? 'uptizm.status.noun_one'
                : 'uptizm.status.noun_other',
          ),
        }),
        requiredPlan: requiredPlan,
        feature: trans('uptizm.status.list_title'),
      ),
    );
  }

  /// Refetch on every mount: the backing controller loads in `onInit`, which
  /// magic fires only once per controller instance, so re-entering this route
  /// would otherwise re-render the data fetched the first time it was ever
  /// opened. See [RefetchesOnMount].
  @override
  Future<void> refetch() => controller.reload();

  @override
  Widget build(BuildContext context) {
    // Compose the page body as a Wind flex column: the 24px header rhythm is
    // carried by gap-6, not a SizedBox spacer.
    return PageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          // 1. Page header with a "New status page" action button.
          MSPageHeader(
            title: trans('uptizm.status.list_title'),
            subtitle: trans('uptizm.status.list_description'),
            actions: [
              MSButton(
                // Proactive cap: below the plan's status-page limit this opens
                // the create flow; at the cap it nudges to upgrade instead of
                // letting the create form 422 on save.
                onPressed: _canCreateStatusPage
                    ? () => MagicRoute.to('/status/new')
                    : _nudgeStatusPageLimit,
                child: WText(trans('uptizm.status.list_new_page_action')),
              ),
            ],
          ),

          // 2. Card grid, or an empty state when no page has ever been created.
          _buildBody(),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Card grid
  // ---------------------------------------------------------------------------

  /// Builds the responsive card grid, a skeleton while the first read is in
  /// flight, or an [MSEmptyState] once the roster is known to be empty.
  Widget _buildBody() {
    // Loading is not emptiness. Without this branch a populated account opened
    // the page on "No status pages yet" and only swapped to its rows when the
    // fetch landed, which reads as "you have none" for as long as the round trip
    // takes.
    if (controller.isFirstLoad) {
      return _buildSkeleton();
    }

    if (controller.statusPages.isEmpty) {
      return _buildEmptyState();
    }

    return WDiv(
      className: 'grid grid-cols-1 sm:grid-cols-2 gap-4',
      children: [
        for (final StatusPage page in controller.statusPages) _buildCard(page),
      ],
    );
  }

  /// Builds a single status-page card: logo tile + name + mono URL + overall
  /// [StatusBadge], and a footer of component/domain/subscriber counts.
  ///
  /// The whole card is tappable via [WAnchor] (the same pointer-cursor + hit
  /// target wrapper used by [MonitorListRow]) and routes to the page's editor.
  Widget _buildCard(StatusPage page) {
    // The page's REAL components, from the eager-loaded pivot. This used to
    // resolve the page's monitor ids through a design-lab fixture list, which
    // never matched a real uuid, so every card claimed "0 components" and (via
    // worstStatus's old empty-list default) "Operational" even while the
    // attached monitors were down.
    final List<PublicComponent> components = page.components;
    final StatusKey? overall = worstStatus(components);
    final int subscriberCount = controller.subscribersFor(page.id).length;

    return WAnchor(
      onTap: () => MagicRoute.to('/status/${page.id}'),
      child: WDiv(
        className:
            'flex flex-col gap-3 rounded-xl border border-color-border '
            'bg-surface p-4 hover:bg-surface-container transition-colors',
        children: [
          WDiv(
            className: 'flex flex-row items-center gap-2.5',
            children: [
              _buildLogoTile(page),
              WDiv(
                className: 'flex flex-col min-w-0 flex-1',
                children: [
                  WText(
                    page.name ?? '',
                    className: 'truncate text-sm font-semibold text-fg',
                  ),
                  WText(
                    pageUrl(page),
                    className: 'truncate font-mono text-xs text-fg-muted',
                  ),
                ],
              ),
              // No components means no measurement to report, so the card shows
              // no badge rather than a healthy-looking one.
              if (overall != null)
                StatusBadge(overall, size: StatusBadgeSize.sm),
            ],
          ),
          WText(
            _footerText(page, components.length, subscriberCount),
            className: 'font-mono text-xs text-fg-muted',
          ),
        ],
      ),
    );
  }

  /// Brand-tinted logo tile with the page's fallback initials in white.
  ///
  /// Mirrors the brand-header logo tile from [StatusPagePreview]: `brandColor`
  /// is content data applied via `WDiv.backgroundColor`, not a semantic token.
  Widget _buildLogoTile(StatusPage page) {
    final String logoText = page.logoText ?? '';
    final String name = page.name ?? '';
    final String initials = logoText.isNotEmpty
        ? logoText
        : (name.isNotEmpty ? name.substring(0, 1) : 'S');

    return WDiv(
      backgroundColor: page.brandColor,
      className: 'size-8 shrink-0 rounded-md flex items-center justify-center',
      child: WText(initials, className: 'text-sm font-bold text-white'),
    );
  }

  /// Footer counts: "N components · Subdomain/Path · N subscribers / Subs off".
  String _footerText(StatusPage page, int componentCount, int subscriberCount) {
    final String componentsLabel = componentCount == 1
        ? trans('uptizm.status.list_card_component_singular')
        : trans('uptizm.status.list_card_component_plural');
    // The enum owns its own localized label, so there is one place to add a
    // mode. A second key set here held byte-identical strings and would have
    // silently gone stale the moment a mode was added.
    final String domainLabel = page.domainMode.label;
    final String subscribersLabel = page.subscriptionsEnabled
        ? '$subscriberCount ${trans('uptizm.status.list_card_subscribers')}'
        : trans('uptizm.status.list_card_subs_off');

    return '$componentCount $componentsLabel · $domainLabel · $subscribersLabel';
  }

  // ---------------------------------------------------------------------------
  // Empty state
  // ---------------------------------------------------------------------------

  /// Builds the "never had a status page" [MSEmptyState] with a New-status-page
  /// action. The dashed-border container mirrors
  /// `rounded-xl border-dashed border-border` from the React source.
  /// Builds the first-load placeholder: the card grid's own shape, in skeletons.
  ///
  /// It mirrors the real card (logo tile, name line, mono URL line, footer line)
  /// at the same grid and spacing, so the layout does not jump when the rows
  /// arrive. Two placeholders, because the grid is 2-up from `sm` and one lone
  /// card would imply the account has exactly one page.
  Widget _buildSkeleton() {
    return WDiv(
      className: 'grid grid-cols-1 sm:grid-cols-2 gap-4',
      children: [for (int i = 0; i < 2; i++) _buildSkeletonCard()],
    );
  }

  /// One skeleton card, matching [_buildCard]'s frame and internal rhythm.
  Widget _buildSkeletonCard() {
    return WDiv(
      className:
          'flex flex-col gap-3 rounded-xl border border-color-border '
          'bg-surface p-4',
      children: const [
        WDiv(
          className: 'flex flex-row items-center gap-2.5',
          children: [
            MSSkeleton(width: 32, height: 32),
            WDiv(
              className: 'flex flex-col flex-1 gap-1.5',
              children: [
                MSSkeleton(shape: SkeletonShape.text, width: 140, height: 20),
                MSSkeleton(shape: SkeletonShape.text, width: 200, height: 16),
              ],
            ),
            MSSkeleton(width: 72, height: 20),
          ],
        ),
        MSSkeleton(shape: SkeletonShape.text, width: 220, height: 16),
      ],
    );
  }

  Widget _buildEmptyState() {
    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: MSEmptyState(
        title: trans('uptizm.status.list_empty_title'),
        description: trans('uptizm.status.list_empty_description'),
        action: MSButton(
          onPressed: () => MagicRoute.to('/status/new'),
          child: WText(trans('uptizm.status.list_new_page_action')),
        ),
      ),
    );
  }
}
