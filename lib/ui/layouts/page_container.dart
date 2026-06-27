import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

/// **The Shared Page Container**
///
/// One source of truth for page width and edge margins across EVERY screen,
/// ported from the design lab's `PageContainer` (Apple HIG layout discipline):
///
/// - A single shared `max-w-6xl` so all pages align identically (no per-page
///   width tuning, consistency over per-screen knobs).
/// - Mobile-first edge margins on the 8pt grid: 16px compact, 20px regular
///   (`sm:`), 32px on wide (`lg:`). Margins scale by breakpoint intent.
/// - Safe-area aware via [SafeArea], not CSS `env()`: horizontal + bottom insets
///   respect the notch / home indicator so content never sits under a rounded
///   corner. The bottom inset is left to the shell (the fixed `BottomNav`
///   already clears the home indicator), so this container only guards the
///   left / right / top edges.
///
/// ### Example
/// ```dart
/// PageContainer(
///   child: WColumn(
///     className: 'gap-6',
///     children: [/* page sections */],
///   ),
/// )
/// ```
@immutable
class PageContainer extends StatelessWidget {
  /// The page content laid out inside the shared container.
  final Widget child;

  /// Optional className appended after the container defaults so a page can
  /// tune spacing without losing the shared width / margin discipline.
  final String? className;

  /// Creates a [PageContainer] wrapping [child].
  const PageContainer({super.key, required this.child, this.className});

  @override
  Widget build(BuildContext context) {
    // 1. Guard the horizontal edges against the device safe area; the shell
    //    owns the top bar and bottom nav, so only left/right matter here.
    return SafeArea(
      top: false,
      bottom: false,
      child: WDiv(
        // 2. Shared max-width + mobile-first edge margins + vertical rhythm.
        //    max-w-6xl centered via mx-auto; padding widens at sm:/lg:.
        className:
            '''
          w-full max-w-6xl mx-auto
          px-4 sm:px-5 lg:px-8
          py-6 sm:py-8
          ${className ?? ''}
        ''',
        child: child,
      ),
    );
  }
}
