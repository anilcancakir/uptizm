import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/status_page_controller.dart';
import '../../../app/support/status_page_support.dart' show pageUrl;
import '../../../app/models/status_page.dart';
import '../../../ui/components/status_page_preview/index.dart';

/// **The full-screen status-page preview screen.**
///
/// A faithful Flutter port of the React `PublicStatusPage`, embedded inside the
/// app shell rather than served standalone: it resolves a status page [id] via
/// [StatusPageController.configById] and renders the [StatusPagePreview] mockup
/// inside a
/// browser-framed [Card] (three chrome dots + a mono URL bar showing
/// [pageUrl]), so it reads as an in-app simulation of the backend-rendered
/// public page rather than a live route.
///
/// When [StatusPageController.configById] returns `null` it renders a graceful
/// not-found [MSEmptyState] (mirroring the React `StatusPageNotFound` copy)
/// instead of crashing on an unknown route id.
///
/// This is a mock screen: nothing here calls the network, and no `/s/:slug`
/// route is registered anywhere in the app (the real public page is
/// backend-rendered; this view is the editor-adjacent, in-app simulation of
/// it).
///
/// ### Example
/// ```dart
/// // Registered as the routed `/status/:id/preview` content (wrapped by the
/// // shell):
/// MagicStarter.view.makeLayout(
///   'layout.app',
///   child: const StatusPagePreviewView(id: 'acme'),
/// )
/// ```
@immutable
class StatusPagePreviewView extends MagicStatefulView<StatusPageController> {
  /// The status-page identifier resolved against the fixtures via
  /// [StatusPageController.configById].
  ///
  /// `null` or an unknown id renders a graceful not-found [MSEmptyState].
  final String? id;

  /// Creates the [StatusPagePreviewView] for the given status page [id].
  const StatusPagePreviewView({super.key, this.id});

  @override
  State<StatusPagePreviewView> createState() => _StatusPagePreviewViewState();
}

class _StatusPagePreviewViewState
    extends
        MagicStatefulViewState<StatusPageController, StatusPagePreviewView> {
  @override
  void initState() {
    Magic.findOrPut(StatusPageController.new);
    super.initState();
  }

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the status page; a null / unknown id falls back to a
    //    graceful not-found state so the screen never crashes on an unknown
    //    route id.
    final StatusPage? page = controller.configById(widget.id);
    if (page == null) {
      // Null means "the roster read has not answered" as often as it means "no
      // such page", and only the second deserves the not-found screen.
      return controller.isFirstLoad ? _buildPending() : _buildNotFound();
    }

    // 2. Header: breadcrumb back to the page's editor, then a centered
    //    browser-framed mockup of the public page inside a scroll area. The
    //    24px header rhythm is carried by gap-6, not a SizedBox spacer.
    final String pageName = page.name ?? '';
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: pageName,
            backLabel: pageName,
            backFallback: '/status/${page.id}',
          ),
          _buildBrowserFrame(page),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Browser frame
  // ---------------------------------------------------------------------------

  /// Builds the centered browser-frame [Card]: a chrome top bar (three dots +
  /// the mono [pageUrl]) wrapping the [StatusPagePreview] inside a bounded
  /// scroll area, so the mockup reads as a simulated browser window rather
  /// than a bare page section.
  Widget _buildBrowserFrame(StatusPage page) {
    return WDiv(
      className: 'w-full max-w-2xl mx-auto',
      child: MSCard(
        noPadding: true,
        child: WDiv(
          className: 'flex flex-col',
          children: [
            _buildChromeBar(page),
            SingleChildScrollView(
              child: WDiv(
                className: 'p-6',
                child: StatusPagePreview(config: page),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Builds the chrome bar: three decorative dots on the leading edge and a
  /// centered mono URL pill showing [pageUrl].
  Widget _buildChromeBar(StatusPage page) {
    return WDiv(
      className:
          'flex flex-row items-center gap-3 border-b '
          'border-color-border bg-surface-container-high px-4 py-3 '
          'rounded-t-lg',
      children: [
        WDiv(
          className: 'flex flex-row items-center gap-1.5',
          children: [_buildChromeDot(), _buildChromeDot(), _buildChromeDot()],
        ),
        WDiv(
          className: 'flex-1 rounded-full bg-surface px-3 py-1',
          child: WText(
            pageUrl(page),
            className: 'text-center font-mono text-xs text-fg-muted',
          ),
        ),
      ],
    );
  }

  /// Builds a single decorative browser-chrome dot.
  Widget _buildChromeDot() {
    return WDiv(className: 'size-2 rounded-full bg-fg-disabled');
  }

  // ---------------------------------------------------------------------------
  // Not-found
  // ---------------------------------------------------------------------------

  /// Builds the pending state shown while the roster read that will decide
  /// whether this page exists is still in flight.
  ///
  /// Every [MSSkeleton] carries an explicit height: it wraps a childless `WDiv`
  /// with nothing of its own to measure, so one without a height lays out 0px
  /// tall and the operator sees a blank screen instead of a placeholder.
  Widget _buildPending() {
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('common.loading'),
            backLabel: trans('uptizm.status.list_title'),
            backFallback: '/status',
          ),
          WDiv(
            className: 'flex flex-col gap-4',
            children: const [
              MSSkeleton(height: 48),
              MSSkeleton(height: 200),
            ],
          ),
        ],
      ),
    );
  }

  /// Builds the graceful not-found state shown when
  /// [StatusPageController.configById] returns null AND the roster read has
  /// already answered.
  ///
  /// Mirrors the React `StatusPageNotFound` copy, localized through [trans].
  Widget _buildNotFound() {
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('uptizm.status.not_found_title'),
            backLabel: trans('uptizm.status.list_title'),
            backFallback: '/status',
          ),
          MSEmptyState(
            icon: Icons.error_outline,
            title: trans('uptizm.status.not_found_title'),
            description: trans('uptizm.status.not_found_description'),
          ),
        ],
      ),
    );
  }
}
