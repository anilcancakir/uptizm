import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import '../../../app/controllers/status_page_controller.dart';
import '../../../app/mocks/status_pages.dart';
import '../../../ui/components/empty_state/index.dart';
import '../../../ui/components/status_badge/index.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Status Pages list screen.**
///
/// Renders every configured public status page from the design-lab mock
/// fixtures (no controller, no network): a page header with a "New status
/// page" action and a responsive card grid, one card per [StatusPageConfig].
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
  @override
  void initState() {
    Magic.findOrPut(StatusPageController.new);
    super.initState();
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
          PageHeader(
            title: trans('uptizm.status.list_title'),
            subtitle: trans('uptizm.status.list_description'),
            actions: [
              Button(
                onPressed: () => MagicRoute.to('/status/new'),
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
        for (final StatusPageConfig page in controller.statusPages)
          _buildCard(page),
      ],
    );
  }

  /// Builds a single status-page card: logo tile + name + mono URL + overall
  /// [StatusBadge], and a footer of component/domain/subscriber counts.
  ///
  /// The whole card is tappable via [WAnchor] (the same pointer-cursor + hit
  /// target wrapper used by [MonitorListRow]) and routes to the page's editor.
  Widget _buildCard(StatusPageConfig page) {
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
                    page.name,
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
  Widget _buildLogoTile(StatusPageConfig page) {
    final String initials = page.logoText.isNotEmpty
        ? page.logoText
        : (page.name.isNotEmpty ? page.name.substring(0, 1) : 'S');

    return WDiv(
      backgroundColor: page.brandColor,
      className: 'size-8 shrink-0 rounded-md flex items-center justify-center',
      child: WText(initials, className: 'text-sm font-bold text-white'),
    );
  }

  /// Footer counts: "N components · Subdomain/Path · N subscribers / Subs off".
  String _footerText(
    StatusPageConfig page,
    int componentCount,
    int subscriberCount,
  ) {
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
        action: Button(
          onPressed: () => MagicRoute.to('/status/new'),
          child: WText(trans('uptizm.status.list_new_page_action')),
        ),
      ),
    );
  }
}
