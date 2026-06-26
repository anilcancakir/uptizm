import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

/// Home view: the placeholder landing page for the freshly scaffolded uptizm
/// application.
///
/// This is intentionally minimal. It exists so the router, the app layout, and
/// `main.dart` compile and the app boots while the real design (Step 2 onward)
/// is built out. Colors flow through the semantic alias tokens so the screen
/// already tracks DESIGN.md in light and dark.
class HomeView extends StatelessWidget {
  /// Creates the [HomeView].
  const HomeView({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'w-full min-h-screen flex items-center justify-center p-8',
      child: const WText(
        'uptizm',
        className: 'text-2xl font-semibold text-fg',
      ),
    );
  }
}
