import 'package:magic/magic.dart';

import '../resources/views/home_view.dart';

/// Application Route Definitions.
///
/// Register all application routes here. This function is called by
/// [RouteServiceProvider.boot()] during the Magic bootstrap lifecycle.
///
/// See also: `lib/app/kernel.dart` for middleware registration.
void registerAppRoutes() {
  // Public placeholder landing. Kept unguarded so the freshly scaffolded app
  // boots straight to the home view without an auth redirect; the real route
  // table (auth-protected screens, app layout) is built out from Step 2.
  MagicRoute.page('/', () => const HomeView()).title('Uptizm');
}
