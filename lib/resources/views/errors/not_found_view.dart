import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

/// **The catch-all fallback for any URL the app cannot route.**
///
/// Rendered by the `/:path(.*)` route registered as the LAST child of the
/// [AppLayout] group in `lib/routes/app.dart` (see that file's docblock for
/// why it must live there rather than as a standalone route). Once the
/// deeplink association files claim `/*` on `app.uptizm.com`, every path on
/// that host opens the app, including a stale notification link, a typo, and
/// a static asset path such as `/main.dart.js`; this view is where all of
/// those land instead of the app opening on nothing.
///
/// Deliberately carries a single action, back to the dashboard. There is no
/// "open in browser" affordance: on web the visitor is already in the
/// browser, and on mobile it would route through magic's `Launch` facade,
/// which answers `false` on refusal and swallows the reason, so the button
/// would silently do nothing on exactly the devices it targets.
///
/// ### Example
/// ```dart
/// MagicRoute.page('/:path(.*)', (String path) => NotFoundView(path: path));
/// ```
@immutable
class NotFoundView extends StatelessWidget {
  /// The unmatched path captured by the `/:path(.*)` route.
  ///
  /// Whether it arrives with a leading slash is not something to assume: this
  /// view first shipped prepending one unconditionally, and a live walk to
  /// `/definitely-not-a-route` rendered `//definitely-not-a-route` at the user.
  /// [_displayPath] normalises instead, so either shape reads correctly.
  final String path;

  /// [path] with exactly one leading slash, which is how a person writes the
  /// address they typed.
  String get _displayPath => path.startsWith('/') ? path : '/$path';

  /// Creates a [NotFoundView] for the unmatched [path].
  const NotFoundView({super.key, required this.path});

  @override
  Widget build(BuildContext context) {
    return MSPageContainer(
      child: MSEmptyState(
        title: trans('uptizm.errors.not_found_title'),
        description: trans('uptizm.errors.not_found_description', {
          'path': _displayPath,
        }),
        action: MSButton(
          onPressed: () => MagicRoute.to('/'),
          child: WText(trans('uptizm.errors.not_found_action')),
        ),
      ),
    );
  }
}
