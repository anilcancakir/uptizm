import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/entitlement_controller.dart';
import '../../../app/controllers/status_page_controller.dart';
import '../../../app/enums/domain_mode.dart' show DomainMode;
import '../../../app/support/status_page_support.dart'
    show pageUrl, worstStatus;
import '../../../app/support/status_page_types.dart' show PublicComponent;
import '../../../app/mocks/status_pages.dart';
import '../../../app/models/status_page.dart';
import '../../../ui/components/empty_state/index.dart';
import '../../../ui/components/status_badge/index.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Status Pages list screen.**
///
/// Renders every configured public status page from the design-lab mock
/// fixtures (no controller, no network): a page header with a "New status
/// page" action and a responsive card grid, one card per [StatusPage].
/// An [EmptyState] placeholder is shown when [statusPages] is empty.
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
    extends MagicStatefulViewState<StatusPageController, StatusPagesListView> {
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
    Magic.error(
      trans('uptizm.status.list_title'),
      trans('uptizm.status.limit_nudge', {
        'plan': _entitlement.planName,
        'count': '$limit',
        'noun': limit == 1 ? 'status page' : 'status pages',
      }),
    );
  }

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

  /// Builds the responsive card grid, or an [EmptyState] when [statusPages]
  /// is empty.
  Widget _buildBody() {
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
    final List<PublicComponent> components = componentsFor(page);
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
              StatusBadge(worstStatus(components), size: StatusBadgeSize.sm),
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
    final String domainLabel = switch (page.domainMode) {
      DomainMode.subdomain => trans('uptizm.status.list_card_subdomain'),
      DomainMode.path => trans('uptizm.status.list_card_path'),
    };
    final String subscribersLabel = page.subscriptionsEnabled
        ? '$subscriberCount ${trans('uptizm.status.list_card_subscribers')}'
        : trans('uptizm.status.list_card_subs_off');

    return '$componentCount $componentsLabel · $domainLabel · $subscribersLabel';
  }

  // ---------------------------------------------------------------------------
  // Empty state
  // ---------------------------------------------------------------------------

  /// Builds the "never had a status page" [EmptyState] with a New-status-page
  /// action. The dashed-border container mirrors
  /// `rounded-xl border-dashed border-border` from the React source.
  Widget _buildEmptyState() {
    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: EmptyState(
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
